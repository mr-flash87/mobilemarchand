package com.flashpay.gateway

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.provider.Telephony
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.concurrent.thread

class MainActivity : Activity() {
    private lateinit var store: Store
    private lateinit var status: TextView
    private lateinit var checks: TextView
    private lateinit var logView: TextView
    private lateinit var pairBox: LinearLayout
    private lateinit var pairedBox: LinearLayout
    private lateinit var urlInput: EditText
    private lateinit var codeInput: EditText
    private val ui = Handler(Looper.getMainLooper())
    private val refresher = object : Runnable {
        override fun run() {
            refresh()
            ui.postDelayed(this, 2000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store.get(this)
        setContentView(buildUi())
        if (store.isPaired) {
            GatewayService.start(this)
            Sync.schedulePeriodic(this)
        }
        askPermissions()
    }

    override fun onResume() {
        super.onResume()
        ui.post(refresher)
    }

    override fun onPause() {
        ui.removeCallbacks(refresher)
        super.onPause()
    }

    // ---------------- Interface ----------------
    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()

    private fun card(): LinearLayout = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        setPadding(dp(16), dp(14), dp(16), dp(14))
        background = GradientDrawable().apply { setColor(Color.WHITE); cornerRadius = dp(14).toFloat() }
        layoutParams = LinearLayout.LayoutParams(-1, -2).apply { bottomMargin = dp(12) }
    }

    private fun text(s: String, size: Float = 15f, bold: Boolean = false, color: Int = 0xFF0F172A.toInt()) = TextView(this).apply {
        text = s
        textSize = size
        setTextColor(color)
        if (bold) setTypeface(typeface, Typeface.BOLD)
    }

    private fun button(label: String, primary: Boolean = false, onClick: () -> Unit) = Button(this).apply {
        text = label
        isAllCaps = false
        setTextColor(if (primary) Color.WHITE else 0xFF0B5FFF.toInt())
        background = GradientDrawable().apply {
            setColor(if (primary) 0xFF0B5FFF.toInt() else 0xFFE8EFFF.toInt())
            cornerRadius = dp(10).toFloat()
        }
        layoutParams = LinearLayout.LayoutParams(-1, dp(48)).apply { topMargin = dp(8) }
        setOnClickListener { onClick() }
    }

    private fun input(hint: String, type: Int) = EditText(this).apply {
        this.hint = hint
        inputType = type
        setSingleLine()
        layoutParams = LinearLayout.LayoutParams(-1, -2).apply { topMargin = dp(6) }
    }

    private fun buildUi(): View {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16), dp(24), dp(16), dp(24))
            setBackgroundColor(0xFFF5F7FB.toInt())
        }
        root.addView(text("Passerelle de paiement", 22f, true))
        root.addView(text("Lit les SMS Mobile Money et confirme les paiements automatiquement.", 14f, color = 0xFF64748B.toInt()).apply {
            setPadding(0, dp(4), 0, dp(14))
        })

        val st = card()
        status = text("", 16f, true)
        checks = text("", 14f, color = 0xFF334155.toInt()).apply { setPadding(0, dp(8), 0, 0) }
        st.addView(status)
        st.addView(checks)
        root.addView(st)

        pairBox = card().apply {
            addView(text("Associer au serveur", 17f, true))
            addView(text("Dans l'admin du site → Téléphones → Ajouter, vous obtenez un code à 6 chiffres.", 13f, color = 0xFF64748B.toInt()))
            urlInput = input("Adresse du site (ex. https://pay.monsite.com)", InputType.TYPE_TEXT_VARIATION_URI)
            urlInput.setText(store.serverUrl)
            codeInput = input("Code d'association (6 chiffres)", InputType.TYPE_CLASS_NUMBER)
            addView(urlInput)
            addView(codeInput)
            addView(button("Associer", primary = true) { pair() })
        }
        root.addView(pairBox)

        pairedBox = card().apply {
            addView(text("Actions", 17f, true))
            addView(button("Envoyer maintenant / tester la connexion", primary = true) { syncNow() })
            addView(button("Renvoyer les SMS des dernières 24 h") { resendInbox() })
            addView(button("Dissocier ce téléphone") { unpair() })
        }
        root.addView(pairedBox)

        val perms = card().apply {
            addView(text("Réglages du téléphone", 17f, true))
            addView(button("Autoriser SMS et notifications") { askPermissions() })
            addView(button("Désactiver l'optimisation de batterie") { askIgnoreBattery() })
            addView(button("Paramètres de l'application") {
                startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
            })
            addView(text("Conseil : laissez le téléphone branché, avec la data ou le Wi-Fi, et ne fermez pas l'application depuis les applis récentes.", 13f, color = 0xFF64748B.toInt()).apply {
                setPadding(0, dp(8), 0, 0)
            })
        }
        root.addView(perms)

        val logCard = card().apply {
            addView(text("Journal", 17f, true))
            logView = text("", 12f, color = 0xFF334155.toInt()).apply {
                typeface = Typeface.MONOSPACE
                setPadding(0, dp(6), 0, 0)
            }
            addView(logView)
        }
        root.addView(logCard)
        return ScrollView(this).apply { addView(root) }
    }

    private fun hasPerm(p: String) = checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED

    private fun ignoringBattery(): Boolean =
        (getSystemService(POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(packageName)

    private fun refresh() {
        val fmt = SimpleDateFormat("dd/MM HH:mm:ss", Locale.FRANCE)
        if (store.isPaired) {
            val ok = store.lastError.isEmpty()
            status.text = if (ok) "🟢 Active : ${store.deviceName}" else "🟠 ${store.lastError}"
            status.setTextColor(if (ok) 0xFF0F9D58.toInt() else 0xFFB45309.toInt())
        } else {
            status.text = "⚪ Non associée"
            status.setTextColor(0xFF64748B.toInt())
        }
        val notif = Build.VERSION.SDK_INT < 33 || hasPerm(Manifest.permission.POST_NOTIFICATIONS)
        checks.text = buildString {
            append(if (hasPerm(Manifest.permission.RECEIVE_SMS)) "✅" else "❌").append(" Réception des SMS\n")
            append(if (notif) "✅" else "❌").append(" Notifications\n")
            append(if (ignoringBattery()) "✅" else "⚠️").append(" Optimisation batterie désactivée\n")
            append(if (GatewayService.running) "✅" else "⚠️").append(" Service actif\n")
            if (store.isPaired) {
                append("Serveur : ").append(store.serverUrl).append('\n')
                append("Dernier contact : ").append(if (store.lastSyncAt > 0) fmt.format(Date(store.lastSyncAt)) else "—").append('\n')
                append("SMS en attente d'envoi : ").append(store.pendingCount())
            }
        }
        pairBox.visibility = if (store.isPaired) View.GONE else View.VISIBLE
        pairedBox.visibility = if (store.isPaired) View.VISIBLE else View.GONE
        logView.text = store.recentLog().ifEmpty { "Aucun événement." }
    }

    // ---------------- Actions ----------------
    private fun toast(s: String) = Toast.makeText(this, s, Toast.LENGTH_LONG).show()

    private fun pair() {
        var url = urlInput.text.toString().trim()
        val code = codeInput.text.toString().trim()
        if (url.isEmpty() || code.length != 6) {
            toast("Saisissez l'adresse du site et le code à 6 chiffres.")
            return
        }
        if (!url.startsWith("http")) url = "https://$url"
        thread {
            try {
                Api.pair(store, url, code)
                store.lastError = ""
                store.log("🔗 Associé au serveur $url")
                runOnUiThread {
                    toast("Téléphone associé ✅")
                    GatewayService.start(this)
                    Sync.schedulePeriodic(this)
                    refresh()
                }
            } catch (e: Exception) {
                runOnUiThread { toast("Échec : ${e.message}") }
            }
        }
    }

    private fun syncNow() {
        thread {
            try {
                val n = Api.flush(this, store)
                Api.heartbeat(this, store)
                store.lastError = ""
                runOnUiThread { toast("Connexion OK ✅ ($n SMS envoyés)") }
            } catch (e: Exception) {
                store.lastError = e.message ?: "Erreur"
                runOnUiThread { toast("Erreur : ${e.message}") }
            }
        }
    }

    @SuppressLint("Range")
    private fun resendInbox() {
        if (!hasPerm(Manifest.permission.READ_SMS)) {
            askPermissions()
            return
        }
        thread {
            var count = 0
            val since = System.currentTimeMillis() - 24 * 3600 * 1000L
            contentResolver.query(
                Telephony.Sms.Inbox.CONTENT_URI,
                arrayOf(Telephony.Sms.ADDRESS, Telephony.Sms.BODY, Telephony.Sms.DATE, Telephony.Sms.DATE_SENT),
                "${Telephony.Sms.DATE} > ?", arrayOf(since.toString()), "${Telephony.Sms.DATE} ASC"
            )?.use { c ->
                while (c.moveToNext()) {
                    val sender = c.getString(0) ?: continue
                    val body = c.getString(1) ?: continue
                    val ts = c.getLong(3).takeIf { it > 0 } ?: c.getLong(2)
                    if (store.isAllowedSender(sender) && store.enqueue(sender, body, ts)) count++
                }
            }
            store.log("📤 $count SMS des dernières 24 h remis en file")
            Sync.now(this)
            runOnUiThread { toast("$count SMS remis en file. Le serveur ignore les doublons.") }
        }
    }

    private fun unpair() {
        AlertDialog.Builder(this)
            .setTitle("Dissocier ?")
            .setMessage("Ce téléphone n'enverra plus les SMS. Il faudra un nouveau code pour le réassocier.")
            .setPositiveButton("Dissocier") { _, _ ->
                store.token = ""
                stopService(Intent(this, GatewayService::class.java))
                store.log("Dissocié")
                refresh()
            }
            .setNegativeButton("Annuler", null)
            .show()
    }

    private fun askPermissions() {
        val wanted = mutableListOf(Manifest.permission.RECEIVE_SMS, Manifest.permission.READ_SMS)
        if (Build.VERSION.SDK_INT >= 33) wanted += Manifest.permission.POST_NOTIFICATIONS
        val missing = wanted.filterNot { hasPerm(it) }
        if (missing.isNotEmpty()) requestPermissions(missing.toTypedArray(), 1)
    }

    @SuppressLint("BatteryLife")
    private fun askIgnoreBattery() {
        if (ignoringBattery()) {
            toast("Déjà désactivée ✅")
            return
        }
        try {
            startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")))
        } catch (e: Exception) {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (!hasPerm(Manifest.permission.RECEIVE_SMS)) {
            toast("Sans la permission SMS, l'application ne peut pas confirmer les paiements.")
        } else if (store.isPaired) {
            GatewayService.start(this)
        }
        refresh()
    }
}
