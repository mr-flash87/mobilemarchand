package com.flashpay.gateway

import android.Manifest
import android.accessibilityservice.AccessibilityService
import android.app.Activity
import android.app.KeyguardManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.telecom.TelecomManager
import android.util.Base64
import android.view.accessibility.AccessibilityNodeInfo
import org.json.JSONObject
import java.security.KeyStore
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Opérateur capable d'envoyer de l'argent (reçu du site) : le code secret, lui, ne quitte jamais ce téléphone. */
data class PayoutOp(val code: String, val name: String, val ussd: String)

/** Envoi demandé par le site. */
data class PayoutJob(val ref: String, val op: String, val opName: String, val phone: String, val amount: Int, val ussd: String, val feePercent: Double = 0.0) {
    companion object {
        fun from(o: JSONObject) = PayoutJob(
            o.getString("id"), o.getString("operator"), o.optString("operator_name", o.getString("operator")),
            o.getString("phone"), o.getInt("amount"), o.getString("ussd"), o.optDouble("fee_percent", 0.0),
        )
    }
}

/* =========================================================================================
 *  Coffre du code secret : chiffré par une clé du « Keystore » Android (non exportable).
 * ========================================================================================= */
object PinVault {
    private const val ALIAS = "payout_pin_key"

    private fun prefs(ctx: Context) = ctx.applicationContext.getSharedPreferences("vault", Context.MODE_PRIVATE)

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build()
        )
        return gen.generateKey()
    }

    fun save(ctx: Context, op: String, pin: String) {
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, key())
        val enc = c.doFinal(pin.toByteArray(Charsets.UTF_8))
        val v = Base64.encodeToString(c.iv, Base64.NO_WRAP) + ":" + Base64.encodeToString(enc, Base64.NO_WRAP)
        prefs(ctx).edit().putString("pin_$op", v).apply()
    }

    fun get(ctx: Context, op: String): String? {
        val v = prefs(ctx).getString("pin_$op", null) ?: return null
        return try {
            val (iv, enc) = v.split(":").map { Base64.decode(it, Base64.NO_WRAP) }
            val c = Cipher.getInstance("AES/GCM/NoPadding")
            c.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, iv))
            String(c.doFinal(enc), Charsets.UTF_8)
        } catch (e: Exception) {
            null
        }
    }

    fun has(ctx: Context, op: String) = prefs(ctx).contains("pin_$op")

    fun clear(ctx: Context, op: String) = prefs(ctx).edit().remove("pin_$op").apply()
}

/* =========================================================================================
 *  Exécution d'un envoi : compose le code USSD, puis le service d'accessibilité
 *  saisit le code secret et lit la réponse de l'opérateur.
 * ========================================================================================= */
object PayoutRunner {
    @Volatile var job: PayoutJob? = null
    @Volatile private var pin: String? = null
    @Volatile var pinSent = false
    @Volatile var sawDialog = false
    /** Applications dont une fenêtre est apparue pendant l'envoi (diagnostic). */
    val seenPackages: MutableSet<String> = java.util.Collections.synchronizedSet(LinkedHashSet())
    @Volatile private var resultText: String? = null
    @Volatile private var failure: String? = null
    @Volatile private var steps = 0
    @Volatile private var latch: CountDownLatch? = null

    private val SIM_ALIASES = mapOf(
        "moov" to listOf("moov", "onatel", "telmob", "flooz"),
        "orange" to listOf("orange"),
        "telecel" to listOf("telecel"),
    )
    private val PIN_RX = Regex("code\\s*secret|code\\s*pin|\\bpin\\b|mot\\s*de\\s*passe|password|secret", RegexOption.IGNORE_CASE)
    private val CONFIRM_RX = Regex("confirm|1\\s*[.:)\\-]?\\s*(oui|valider|accepter|confirmer)|tapez\\s*1", RegexOption.IGNORE_CASE)
    private val SEND_RX = Regex("^(envoyer|send|ok|r[ée]pondre|reply|valider|oui|confirmer|soumettre|submit)$", RegexOption.IGNORE_CASE)
    private val CLOSE_RX = Regex("^(annuler|cancel|fermer|close|ok|ignorer|dismiss|quitter)$", RegexOption.IGNORE_CASE)

    fun accessibilityOn(ctx: Context): Boolean {
        val flat = Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES) ?: return false
        val me = ComponentName(ctx, UssdAccessibilityService::class.java)
        return flat.split(":").any { ComponentName.unflattenFromString(it) == me }
    }

    fun callPermission(ctx: Context) = ctx.checkSelfPermission(Manifest.permission.CALL_PHONE) == PackageManager.PERMISSION_GRANTED

    /** Opérateurs pour lesquels un code secret est enregistré. */
    fun readyOperators(ctx: Context, store: Store): List<String> =
        store.payoutOperators.filter { PinVault.has(ctx, it.code) || (it.ussd.startsWith("app:") && store.appPayoutOn(it.code)) }.map { it.code }

    fun ready(ctx: Context, store: Store): Boolean {
        if (!store.isPaired || !store.payoutsEnabled || !accessibilityOn(ctx)) return false
        val ops = readyOperators(ctx, store)
        if (ops.isEmpty()) return false
        // L'autorisation « Appels » n'est utile que pour les codes USSD (pas pour Wave).
        val needsCall = store.payoutOperators.any { it.code in ops && !it.ussd.startsWith("app:") }
        return !needsCall || callPermission(ctx)
    }

    /** Opérateurs réellement utilisables maintenant (USSD seulement si l'autorisation Appels est donnée). */
    fun usableOperators(ctx: Context, store: Store): List<String> {
        val call = callPermission(ctx)
        return readyOperators(ctx, store).filter { code -> call || store.payoutOperators.firstOrNull { it.code == code }?.ussd?.startsWith("app:") == true }
    }

    /** Bloquant (≈ 90 s max) : à appeler hors du thread principal. Retourne (statut, message, code secret envoyé). */
    fun run(ctx: Context, store: Store, j: PayoutJob): Triple<String, String, Boolean> {
        if (j.ussd.startsWith("app:wave")) return WaveDriver.run(ctx, j, PinVault.get(ctx, j.op))
        val secret = PinVault.get(ctx, j.op) ?: return Triple("failed", "Code secret ${j.opName} non enregistré sur le téléphone", false)
        job = j; pin = secret; pinSent = false; sawDialog = false; resultText = null; failure = null; steps = 0
        seenPackages.clear()
        val l = CountDownLatch(1).also { latch = it }
        val pm = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        @Suppress("DEPRECATION")
        val screen = pm.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE, "gateway:ussd"
        )
        try {
            screen.acquire(300_000)
            try {
                ctx.startActivity(Intent(ctx, WakeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_ANIMATION))
            } catch (_: Exception) {
            }
            Thread.sleep(1500)
            val tried = mutableListOf<android.telecom.PhoneAccountHandle?>()
            var attempt = 0
            while (true) {
                val (err, used) = dial(ctx, store, j, tried)
                if (err != null) return Triple("failed", err, false)
                tried += used
                latch!!.await(90, TimeUnit.SECONDS)
                Thread.sleep(300)
                val res = resultText ?: failure ?: ""
                // « Réseau mobile non disponible » avant le code secret : rien n'est parti, on essaie une autre SIM.
                if (!pinSent && NETWORK_RX.containsMatchIn(res) && attempt < 2 && hasOtherSim(ctx, tried)) {
                    store.log("📶 ${j.opName} : « ${res.take(60)} », nouvel essai sur l'autre SIM")
                    UssdAccessibilityService.instance?.performGlobalAction(AccessibilityService.GLOBAL_ACTION_BACK)
                    Thread.sleep(1500)
                    resultText = null; failure = null; steps = 0; sawDialog = false
                    latch = CountDownLatch(1)
                    attempt++
                    continue
                }
                break
            }
            return when {
                failure != null -> Triple(if (pinSent) "unknown" else "failed", failure!!, pinSent)
                resultText != null -> Triple("done", resultText!!, pinSent)
                !sawDialog -> Triple("failed", "Aucune fenêtre USSD reconnue (fenêtres vues : ${seenPackages.joinToString(", ").ifEmpty { "aucune" }}). Accessibilité activée ? Écran sans code ?", false)
                else -> Triple(if (pinSent) "unknown" else "failed", "Pas de réponse finale de l'opérateur", pinSent)
            }
        } finally {
            UssdAccessibilityService.instance?.performGlobalAction(AccessibilityService.GLOBAL_ACTION_BACK)
            job = null; pin = null; latch = null
            if (screen.isHeld) screen.release()
        }
    }

    private val NETWORK_RX = Regex("r[ée]seau|network|non disponible|not available|indisponible|no service|aucun service|hors service", RegexOption.IGNORE_CASE)

    private fun hasOtherSim(ctx: Context, tried: List<android.telecom.PhoneAccountHandle?>): Boolean = try {
        val tm = ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager
        tm.callCapablePhoneAccounts.any { it !in tried }
    } catch (_: SecurityException) {
        false
    }

    /** Compose le code USSD sur la SIM de l'opérateur. Retourne (erreur ou null, SIM utilisée). */
    private fun dial(ctx: Context, store: Store, j: PayoutJob, avoid: List<android.telecom.PhoneAccountHandle?> = emptyList()): Pair<String?, android.telecom.PhoneAccountHandle?> {
        if (!callPermission(ctx)) return "Autorisation « Appels » manquante" to null
        val tm = ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager
        val extras = Bundle()
        if (ctx.checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
            try {
                val all = tm.callCapablePhoneAccounts
                if (avoid.isEmpty()) {
                    store.log("📱 SIM vues : " + all.joinToString(", ") { tm.getPhoneAccount(it)?.label?.toString() ?: "?" }.ifEmpty { "aucune" })
                }
                val accounts = activeAccounts(ctx, tm, all)
                val retry = all.filter { it !in avoid }
                if (avoid.isNotEmpty() && retry.isNotEmpty()) {
                    // Nouvel essai : la SIM suivante (même si Android la croit inactive).
                    extras.putParcelable(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, (retry.firstOrNull { it in accounts } ?: retry[0]))
                } else if (accounts.size == 1 && all.size > 1) {
                    extras.putParcelable(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, accounts[0])
                } else if (accounts.size > 1) {
                    val choice = store.simFor(j.op)
                    // Noms possibles de la SIM (ex. Moov Africa Burkina s'affiche souvent « Onatel » ou « Telmob »).
                    val aliases = (SIM_ALIASES[j.op.lowercase()] ?: emptyList()) + listOf(j.op.lowercase(), j.opName.lowercase().substringBefore(' '))
                    val labels = accounts.map { tm.getPhoneAccount(it)?.label?.toString() ?: "?" }
                    val handle = if (choice in accounts.indices) accounts[choice] else accounts.firstOrNull { h ->
                        val label = tm.getPhoneAccount(h)?.label?.toString()?.lowercase() ?: ""
                        aliases.any { a -> a.isNotEmpty() && label.contains(a) }
                    }
                    if (handle == null) return "Impossible de savoir quelle SIM est ${j.opName} (SIM du téléphone : ${labels.joinToString(", ")}) : choisissez SIM 1 ou SIM 2 dans l'application, onglet Envois" to null
                    extras.putParcelable(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, handle)
                }
            } catch (_: SecurityException) {
            }
        }
        @Suppress("DEPRECATION")
        val h = extras.getParcelable<android.telecom.PhoneAccountHandle>(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE)
        try {
            val simLabel = h?.let { tm.getPhoneAccount(it)?.label?.toString() } ?: "par défaut"
            store.log("📞 ${j.opName} : code composé sur la SIM « $simLabel »")
        } catch (_: Exception) {
        }
        return try {
            tm.placeCall(Uri.fromParts("tel", j.ussd, null), extras)
            null to h
        } catch (e: Exception) {
            "Impossible de composer le code USSD : ${e.message}" to h
        }
    }

    /** SIM réellement actives (une SIM désactivée reste parfois listée par Android). */
    private fun activeAccounts(ctx: Context, tm: TelecomManager, all: List<android.telecom.PhoneAccountHandle>): List<android.telecom.PhoneAccountHandle> {
        if (all.size <= 1) return all
        return try {
            val sm = ctx.getSystemService(android.telephony.SubscriptionManager::class.java)
            val subs = sm.activeSubscriptionInfoList ?: return all
            if (subs.isEmpty()) return all
            val keys = subs.flatMap { listOfNotNull(it.subscriptionId.toString(), try { it.iccId } catch (_: Exception) { null }) }.filter { it.isNotEmpty() }
            val byId = all.filter { h -> keys.any { k -> h.id == k || h.id.startsWith(k) || k.startsWith(h.id) } }
            if (byId.isNotEmpty()) return byId
            // Identifiants illisibles : on compare les noms des SIM actives.
            val names = subs.flatMap { listOf(it.displayName?.toString()?.lowercase() ?: "", it.carrierName?.toString()?.lowercase() ?: "") }.filter { it.isNotEmpty() }
            val byName = all.filter { h -> val l = tm.getPhoneAccount(h)?.label?.toString()?.lowercase() ?: ""; names.any { n -> l.contains(n) || n.contains(l) } }
            if (byName.isNotEmpty()) byName else all
        } catch (_: Exception) {
            all
        }
    }

    /** Appelé par le service d'accessibilité pour chaque fenêtre USSD affichée pendant un envoi. */
    fun handle(service: AccessibilityService, message: String, input: AccessibilityNodeInfo?, buttons: List<AccessibilityNodeInfo>) {
        val secret = pin ?: return
        steps++
        if (steps > 6) {
            fail("Trop d'étapes USSD : $message", buttons, service); return
        }
        if (input != null) {
            when {
                PIN_RX.containsMatchIn(message) -> {
                    if (pinSent) { fail("Code secret refusé ou redemandé : $message", buttons, service); return }
                    setText(input, secret)
                    pinSent = true
                    click(buttons, SEND_RX, preferLast = true)
                }
                CONFIRM_RX.containsMatchIn(message) -> {
                    setText(input, "1")
                    click(buttons, SEND_RX, preferLast = true)
                }
                else -> fail("Étape USSD inattendue : $message", buttons, service)
            }
        } else {
            // Message final de l'opérateur (succès ou erreur) : on le lit puis on ferme la fenêtre.
            resultText = message
            if (!click(buttons, CLOSE_RX, preferLast = false)) service.performGlobalAction(AccessibilityService.GLOBAL_ACTION_BACK)
            latch?.countDown()
        }
    }

    private fun fail(msg: String, buttons: List<AccessibilityNodeInfo>, service: AccessibilityService) {
        failure = msg.take(400)
        if (!click(buttons, Regex("^(annuler|cancel|fermer|close)$", RegexOption.IGNORE_CASE), preferLast = false)) {
            service.performGlobalAction(AccessibilityService.GLOBAL_ACTION_BACK)
        }
        latch?.countDown()
    }

    private fun setText(node: AccessibilityNodeInfo, value: String) {
        node.performAction(AccessibilityNodeInfo.ACTION_FOCUS)
        val args = Bundle().apply { putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, value) }
        node.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)
    }

    private fun click(buttons: List<AccessibilityNodeInfo>, rx: Regex, preferLast: Boolean): Boolean {
        val match = buttons.firstOrNull { rx.matches(it.text?.toString()?.trim() ?: "") }
            ?: if (preferLast) buttons.lastOrNull { !Regex("annuler|cancel", RegexOption.IGNORE_CASE).containsMatchIn(it.text?.toString() ?: "") } else null
        return match?.performAction(AccessibilityNodeInfo.ACTION_CLICK) ?: false
    }
}

/** Écran transparent : allume l'écran et passe l'écran de verrouillage (s'il n'a pas de code) pendant un envoi. */
class WakeActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 27) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
            (getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager).requestDismissKeyguard(this, null)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                android.view.WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    android.view.WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                    android.view.WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD
            )
        }
        Handler(Looper.getMainLooper()).postDelayed({ finish() }, 1200)
    }
}

/* =========================================================================================
 *  Envois Wave : le téléphone pilote l'application Wave comme une personne.
 *  Accueil → Transfert → Saisir un nouveau numéro → Nom complet + Téléphone → Suivant
 *  → Montant reçu → Envoyer. Wave n'a pas de code USSD.
 * ========================================================================================= */
object WaveDriver {
    private const val NAME = "Client Paiement"
    private val PIN_SCREEN = Regex("code\\s*secret|saisi\\w*\\s+(votre\\s+)?code|entrez\\s+(votre\\s+)?code|tapez\\s+(votre\\s+)?code|votre\\s+code|code\\s*pin|code d'acc[eè]s|d[ée]verrouill|mot de passe|\\bpin\\b", RegexOption.IGNORE_CASE)
    private val WAVE_FLOW = Regex("Montant Re|Montant Envoy|Nom complet|Saisir un nouveau num", RegexOption.IGNORE_CASE)
    private val SUCCESS = Regex("envoy[ée]\\s*!|transfert\\s+r[ée]ussi|succ[eè]s|r[ée]ussi|effectu[ée]", RegexOption.IGNORE_CASE)

    fun wavePackage(ctx: Context): String? {
        val pm = ctx.packageManager
        val list = pm.queryIntentActivities(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER), 0)
            .map { it.activityInfo.packageName }.filter { it.startsWith("com.wave.") }.distinct()
        return list.firstOrNull { it.contains("personal") } ?: list.firstOrNull { !it.contains("business") } ?: list.firstOrNull()
    }

    private fun flatten(n: AccessibilityNodeInfo?, out: MutableList<AccessibilityNodeInfo>, depth: Int = 0) {
        if (n == null || depth > 30) return
        out += n
        for (i in 0 until n.childCount) flatten(n.getChild(i), out, depth + 1)
    }

    private fun label(n: AccessibilityNodeInfo): String =
        (n.text?.toString() ?: n.contentDescription?.toString() ?: "").trim()

    private fun clickNode(n: AccessibilityNodeInfo?): Boolean {
        var c = n
        var i = 0
        while (c != null && i < 6) {
            if (c.isClickable && c.isEnabled) return c.performAction(AccessibilityNodeInfo.ACTION_CLICK)
            c = c.parent; i++
        }
        return false
    }

    private fun setText(n: AccessibilityNodeInfo, v: String) {
        n.performAction(AccessibilityNodeInfo.ACTION_CLICK)
        n.performAction(AccessibilityNodeInfo.ACTION_FOCUS)
        val b = Bundle().apply { putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, v) }
        n.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, b)
    }

    /** Saisie lettre par lettre, comme une personne (Wave valide le champ à chaque touche). */
    private fun typeText(n: AccessibilityNodeInfo, v: String) {
        n.performAction(AccessibilityNodeInfo.ACTION_CLICK)
        n.performAction(AccessibilityNodeInfo.ACTION_FOCUS)
        for (i in 1..v.length) {
            val b = Bundle().apply { putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, v.substring(0, i)) }
            n.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, b)
            Thread.sleep(90)
        }
    }

    /** Le bouton (ou son conteneur cliquable) est-il actif ? */
    private fun isActive(n: AccessibilityNodeInfo?): Boolean {
        var c = n
        var i = 0
        while (c != null && i < 6) {
            if (!c.isEnabled) return false
            if (c.isClickable) return true
            c = c.parent; i++
        }
        return n?.isEnabled ?: false
    }

    /** Appui « avec le doigt » au centre de l'élément (si le clic d'accessibilité ne passe pas). */
    private fun tap(svc: AccessibilityService, n: AccessibilityNodeInfo?): Boolean {
        if (n == null || Build.VERSION.SDK_INT < 24) return false
        val r = android.graphics.Rect()
        n.getBoundsInScreen(r)
        if (r.isEmpty) return false
        val path = android.graphics.Path().apply { moveTo(r.exactCenterX(), r.exactCenterY()) }
        val g = android.accessibilityservice.GestureDescription.Builder()
            .addStroke(android.accessibilityservice.GestureDescription.StrokeDescription(path, 0, 80))
            .build()
        return svc.dispatchGesture(g, null, null)
    }

    /** Appui « au doigt » d'abord (le plus fiable avec Wave), sinon clic d'accessibilité. */
    private fun tapFirst(svc: AccessibilityService, n: AccessibilityNodeInfo?): String {
        if (n == null) return "bouton introuvable"
        if (tap(svc, n)) return "appui au doigt"
        return press(svc, n)
    }

    /** Appui robuste : clic d'accessibilité sur l'élément et ses parents, puis appui « au doigt ». Retourne la méthode utilisée. */
    private fun press(svc: AccessibilityService, n: AccessibilityNodeInfo?): String {
        if (n == null) return "bouton introuvable"
        if (clickNode(n)) return "clic"
        var c: AccessibilityNodeInfo? = n
        var i = 0
        while (c != null && i < 6) {
            if (c.performAction(AccessibilityNodeInfo.ACTION_CLICK)) return "clic parent $i"
            c = c.parent; i++
        }
        return if (tap(svc, n)) "appui au doigt" else "échec de l'appui"
    }

    /** Signature de la 1re opération de l'historique (pour reconnaître la nouvelle). */
    private fun topTransaction(nodes: List<AccessibilityNodeInfo>): String {
        val i = nodes.indexOfFirst { Regex("^(À|A|De)\\s").containsMatchIn(label(it)) && Regex("\\d{2} \\d{2} \\d{2} \\d{2}").containsMatchIn(label(it)) }
        if (i < 0) return ""
        return nodes.subList(i, minOf(nodes.size, i + 4)).joinToString(" | ") { label(it) }
    }

    fun run(ctx: Context, j: PayoutJob, pin: String?): Triple<String, String, Boolean> {
        val store = Store.get(ctx)
        fun log(line: String) = store.log("🌊 " + line.take(150))
        val svc = UssdAccessibilityService.instance ?: return Triple("failed", "Accessibilité désactivée : activez « Passerelle Pay : envois d'argent »", false)
        val pkg = wavePackage(ctx) ?: return Triple("failed", "Application Wave introuvable sur ce téléphone", false)
        val launch = ctx.packageManager.getLaunchIntentForPackage(pkg) ?: return Triple("failed", "Impossible d'ouvrir Wave", false)
        val pm = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        @Suppress("DEPRECATION")
        val screen = pm.newWakeLock(PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE, "gateway:wave")
        val phoneFmt = j.phone.chunked(2).joinToString(" ")
        var sent = false
        var formDone = false
        var amountDone = false
        var formTries = 0
        var formPresses = 0
        var sendPresses = 0
        // Montant envoyé = montant + frais (ex. 0,5 %), arrondi au multiple de 5 supérieur (100 -> 105, 1000 -> 1005).
        val sendAmount = (Math.ceil(j.amount * (1 + j.feePercent / 100.0) / 5.0) * 5).toInt()
        var pinAt = 0L
        var initialTop: String? = null
        var lastSeen = ""
        PayoutRunner.job = j
        try {
            screen.acquire(200_000)
            try {
                ctx.startActivity(Intent(ctx, WakeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_ANIMATION))
            } catch (_: Exception) {
            }
            Thread.sleep(1500)
            ctx.startActivity(launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
            val deadline = System.currentTimeMillis() + 150_000
            var sentAt = 0L
            while (System.currentTimeMillis() < deadline) {
                Thread.sleep(800)
                val root = svc.rootInActiveWindow ?: continue
                if (root.packageName?.toString() != pkg) continue
                val nodes = mutableListOf<AccessibilityNodeInfo>().also { flatten(root, it) }
                val all = nodes.joinToString(" ") { label(it) }
                lastSeen = all.take(300)
                fun find(rx: Regex) = nodes.firstOrNull { rx.containsMatchIn(label(it)) }
                val edits = nodes.filter { it.isEditable }

                if (Regex("solde insuffisant", RegexOption.IGNORE_CASE).containsMatchIn(all)) {
                    return Triple("failed", "Wave : solde insuffisant pour envoyer ${j.amount} F (+ frais)", false)
                }
                // Code secret demandé par Wave (pavé numérique de l'application)
                val digits = nodes.filter { Regex("^[0-9]$").matches(label(it)) }
                // Écran du code d'ouverture de Wave (4 chiffres) : pavé de touches 0-9 ou champ caché + clavier.
                val keyCount = digits.map { label(it) }.distinct().size
                lastSeen = "champs=${edits.size}, touches=$keyCount · " + all.take(260)
                val pinScreen = !WAVE_FLOW.containsMatchIn(all) &&
                    (PIN_SCREEN.containsMatchIn(all) || (keyCount >= 10 && find(Regex("^Transfert$", RegexOption.IGNORE_CASE)) == null))
                if (pinScreen) {
                    if (pin == null) return Triple(if (sent) "unknown" else "failed", "Wave demande son code à 4 chiffres : enregistrez-le dans l'application (onglet Envois, ligne Wave)", sent)
                    if (System.currentTimeMillis() - pinAt > 6000) {
                        pinAt = System.currentTimeMillis()
                        if (keyCount >= 10) {
                            for (d in pin) {
                                clickNode(digits.firstOrNull { label(it) == d.toString() })
                                Thread.sleep(250)
                            }
                        } else if (edits.isNotEmpty()) {
                            setText(edits[0], pin)
                        }
                    }
                    continue
                }
                when {
                    sent -> {
                        val top = topTransaction(nodes)
                        if (top.isNotEmpty() && top.contains(phoneFmt) && top != initialTop) {
                            return Triple("success", "Wave : envoyé $sendAmount F (le client reçoit ~${j.amount} F) à $phoneFmt · $top", true)
                        }
                        if (SUCCESS.containsMatchIn(all) && find(Regex("^Montant Re", RegexOption.IGNORE_CASE)) == null) {
                            find(Regex("^(Terminé|Fermer|OK|Retour à l'accueil)$", RegexOption.IGNORE_CASE))?.let { tapFirst(svc, it) }
                            return Triple("done", "Wave : ${all.take(300)}", true)
                        }
                        find(Regex("^(Confirmer|Continuer|Valider)$", RegexOption.IGNORE_CASE))?.let { tapFirst(svc, it) }
                        // Toujours sur l'écran du montant : l'appui sur « Envoyer » n'a pas pris, on réappuie (3 fois max).
                        val btn = nodes.firstOrNull { label(it).equals("Envoyer", true) }
                        if (btn != null && find(Regex("Montant Envoy|Montant Re", RegexOption.IGNORE_CASE)) != null &&
                            System.currentTimeMillis() - sentAt > 5_000 && sendPresses < 3) {
                            sendPresses++
                            log("Wave : toujours sur le montant, nouvel appui sur Envoyer (${sendPresses}) → ${tapFirst(svc, btn)}")
                            sentAt = System.currentTimeMillis()
                        }
                        if (System.currentTimeMillis() - sentAt > 45_000) break
                    }
                    find(Regex("Montant Envoy|Montant Re|Frais Wave", RegexOption.IGNORE_CASE)) != null -> {
                        val btn = nodes.firstOrNull { label(it).equals("Envoyer", true) }
                        lastSeen = "écran montant, Envoyer actif=${isActive(btn)}, champs=${edits.size} · " + all.take(220)
                        if (!amountDone) {
                            // 1er champ = « Montant envoyé » : montant + frais Wave, arrondi au multiple de 5 supérieur
                            // (Wave n'accepte que des montants finissant par 0 ou 5). Wave calcule « Montant reçu ».
                            val target = edits.firstOrNull()
                            if (target != null) {
                                typeText(target, sendAmount.toString())
                                amountDone = true
                                Thread.sleep(1500)
                                log("Wave : montant envoyé « ${target.text} » (reçu par le client : ${edits.getOrNull(1)?.text ?: "?"})")
                            }
                            continue
                        }
                        val how = tapFirst(svc, btn)
                        log("Wave : Envoyer (actif=${isActive(btn)}) → $how")
                        if (btn != null) {
                            sent = true
                            sentAt = System.currentTimeMillis()
                            sendPresses = 1
                        }
                        Thread.sleep(2500)
                    }
                    find(Regex("^Nom complet", RegexOption.IGNORE_CASE)) != null -> {
                        val next = nodes.firstOrNull { label(it).equals("Suivant", true) }
                        lastSeen = "écran nom/téléphone, Suivant actif=${isActive(next)}, champs=${edits.size}, appuis=$formPresses · " + all.take(200)
                        val phoneOk = edits.size >= 2 && (edits[1].text?.toString() ?: "").filter { it.isDigit() }.endsWith(j.phone)
                        if (edits.size >= 2 && (!formDone || (!phoneOk && formTries < 3))) {
                            typeText(edits[0], NAME)
                            Thread.sleep(300)
                            typeText(edits[1], if (formTries == 0) j.phone else phoneFmt)
                            formDone = true
                            formTries++
                            log("Wave : nom et numéro saisis : « ${edits[0].text} » / « ${edits[1].text} »")
                            Thread.sleep(800)
                            continue
                        }
                        if (formPresses >= 6) {
                            return Triple("failed", "Wave : le bouton « Suivant » ne réagit pas (6 appuis). $lastSeen", false)
                        }
                        formPresses++
                        log("Wave : Suivant (actif=${isActive(next)}) → ${tapFirst(svc, next)}")
                        Thread.sleep(2500)
                    }
                    find(Regex("Saisir un nouveau num", RegexOption.IGNORE_CASE)) != null ->
                        clickNode(find(Regex("Saisir un nouveau num", RegexOption.IGNORE_CASE)))
                    find(Regex("^Transfert$", RegexOption.IGNORE_CASE)) != null -> {
                        if (initialTop == null) initialTop = topTransaction(nodes)
                        clickNode(find(Regex("^Transfert$", RegexOption.IGNORE_CASE)))
                    }
                }
            }
            return if (sent) Triple("unknown", "Wave : pas de confirmation visible après « Envoyer ». Dernier écran : $lastSeen", true)
            else Triple("failed", "Wave : écran inattendu, rien n'a été envoyé. Dernier écran : $lastSeen", false)
        } finally {
            PayoutRunner.job = null
            svc.performGlobalAction(AccessibilityService.GLOBAL_ACTION_HOME)
            if (screen.isHeld) screen.release()
        }
    }
}
