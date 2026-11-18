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
data class PayoutJob(val ref: String, val op: String, val opName: String, val phone: String, val amount: Int, val ussd: String) {
    companion object {
        fun from(o: JSONObject) = PayoutJob(
            o.getString("id"), o.getString("operator"), o.optString("operator_name", o.getString("operator")),
            o.getString("phone"), o.getInt("amount"), o.getString("ussd"),
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
    @Volatile private var resultText: String? = null
    @Volatile private var failure: String? = null
    @Volatile private var steps = 0
    @Volatile private var latch: CountDownLatch? = null

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
        store.payoutOperators.filter { PinVault.has(ctx, it.code) }.map { it.code }

    fun ready(ctx: Context, store: Store): Boolean =
        store.isPaired && store.payoutsEnabled && accessibilityOn(ctx) && callPermission(ctx) && readyOperators(ctx, store).isNotEmpty()

    /** Bloquant (≈ 90 s max) : à appeler hors du thread principal. Retourne (statut, message, code secret envoyé). */
    fun run(ctx: Context, store: Store, j: PayoutJob): Triple<String, String, Boolean> {
        val secret = PinVault.get(ctx, j.op) ?: return Triple("failed", "Code secret ${j.opName} non enregistré sur le téléphone", false)
        job = j; pin = secret; pinSent = false; sawDialog = false; resultText = null; failure = null; steps = 0
        val l = CountDownLatch(1).also { latch = it }
        val pm = ctx.getSystemService(Context.POWER_SERVICE) as PowerManager
        @Suppress("DEPRECATION")
        val screen = pm.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE, "gateway:ussd"
        )
        try {
            screen.acquire(120_000)
            try {
                ctx.startActivity(Intent(ctx, WakeActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_ANIMATION))
            } catch (_: Exception) {
            }
            Thread.sleep(1500)
            dial(ctx, store, j)?.let { return Triple("failed", it, false) }
            l.await(90, TimeUnit.SECONDS)
            Thread.sleep(300)
            return when {
                failure != null -> Triple(if (pinSent) "unknown" else "failed", failure!!, pinSent)
                resultText != null -> Triple("done", resultText!!, pinSent)
                !sawDialog -> Triple("failed", "Aucune fenêtre USSD lue (accessibilité désactivée ou écran verrouillé par un code ?)", false)
                else -> Triple(if (pinSent) "unknown" else "failed", "Pas de réponse finale de l'opérateur", pinSent)
            }
        } finally {
            UssdAccessibilityService.instance?.performGlobalAction(AccessibilityService.GLOBAL_ACTION_BACK)
            job = null; pin = null; latch = null
            if (screen.isHeld) screen.release()
        }
    }

    /** Compose le code USSD sur la SIM de l'opérateur. Retourne un message d'erreur, ou null. */
    private fun dial(ctx: Context, store: Store, j: PayoutJob): String? {
        if (!callPermission(ctx)) return "Autorisation « Appels » manquante"
        val tm = ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager
        val extras = Bundle()
        if (ctx.checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
            try {
                val accounts = tm.callCapablePhoneAccounts
                if (accounts.size > 1) {
                    val choice = store.simFor(j.op)
                    val handle = if (choice in accounts.indices) accounts[choice] else accounts.firstOrNull { h ->
                        val label = tm.getPhoneAccount(h)?.label?.toString()?.lowercase() ?: ""
                        label.contains(j.op.lowercase()) || label.contains(j.opName.lowercase().substringBefore(' '))
                    }
                    if (handle == null) return "Impossible de savoir quelle SIM est ${j.opName} : choisissez-la dans l'application (Envois d'argent)"
                    extras.putParcelable(TelecomManager.EXTRA_PHONE_ACCOUNT_HANDLE, handle)
                }
            } catch (_: SecurityException) {
            }
        }
        return try {
            tm.placeCall(Uri.fromParts("tel", j.ussd, null), extras)
            null
        } catch (e: Exception) {
            "Impossible de composer le code USSD : ${e.message}"
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
