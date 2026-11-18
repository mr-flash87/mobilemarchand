package com.flashpay.gateway

import android.accessibilityservice.AccessibilityService
import android.os.SystemClock
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

/**
 * Lit et remplit les fenêtres USSD (Orange, Moov…) UNIQUEMENT pendant un envoi d'argent
 * demandé par le site. En dehors d'un envoi, tous les événements sont ignorés.
 */
class UssdAccessibilityService : AccessibilityService() {
    private var lastSig = ""
    private var lastAt = 0L

    override fun onServiceConnected() {
        instance = this
    }

    override fun onDestroy() {
        instance = null
        super.onDestroy()
    }

    override fun onInterrupt() {}

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (PayoutRunner.job == null || event == null) return
        if (PayoutRunner.job?.ussd?.startsWith("app:") == true) return // envoi Wave : piloté par WaveDriver
        // UNIQUEMENT les fenêtres du système téléphonique (USSD). Jamais l'écran d'accueil, la messagerie,
        // les notifications ni notre propre application.
        val pkg = event.packageName?.toString() ?: return
        if (PayoutRunner.seenPackages.size < 12) PayoutRunner.seenPackages += pkg
        if (pkg == packageName || !USSD_PKG.containsMatchIn(pkg) || EXCLUDED.containsMatchIn(pkg)) return
        val root = ussdRoot(event) ?: return

        val texts = mutableListOf<String>()
        val buttons = mutableListOf<AccessibilityNodeInfo>()
        var input: AccessibilityNodeInfo? = null
        walk(root, 0) { n ->
            val c = n.className?.toString() ?: ""
            when {
                n.isEditable || c.endsWith("EditText") -> if (input == null) input = n
                c.endsWith("Button") -> buttons += n
                else -> n.text?.toString()?.trim()?.takeIf { it.isNotEmpty() }?.let { texts += it }
            }
        }
        val message = texts.distinct().joinToString(" ").replace(Regex("\\s+"), " ").trim()
        if (message.isEmpty()) return
        // « Exécution du code USSD… » : fenêtre d'attente, rien à faire.
        if (input == null && (buttons.isEmpty() || message.length < 60) && RUNNING.containsMatchIn(message)) return
        PayoutRunner.sawDialog = true

        val sig = message + "|" + (input != null)
        val now = SystemClock.uptimeMillis()
        if (sig == lastSig && now - lastAt < 5000) return // même fenêtre déjà traitée
        lastSig = sig
        lastAt = now
        PayoutRunner.handle(this, message, input, buttons)
    }

    /** Racine de la fenêtre USSD : la fenêtre active si elle appartient au téléphone, sinon celle de l'événement. */
    private fun ussdRoot(event: AccessibilityEvent): AccessibilityNodeInfo? {
        rootInActiveWindow?.let { r ->
            val p = r.packageName?.toString() ?: ""
            if (USSD_PKG.containsMatchIn(p) && !EXCLUDED.containsMatchIn(p)) return r
        }
        var n = event.source ?: return null
        var i = 0
        while (i < 40) {
            n = n.parent ?: break
            i++
        }
        val p = n.packageName?.toString() ?: ""
        return if (USSD_PKG.containsMatchIn(p) && !EXCLUDED.containsMatchIn(p)) n else null
    }

    private fun walk(n: AccessibilityNodeInfo?, depth: Int, visit: (AccessibilityNodeInfo) -> Unit) {
        if (n == null || depth > 25) return
        visit(n)
        for (i in 0 until n.childCount) walk(n.getChild(i), depth + 1, visit)
    }

    companion object {
        @Volatile var instance: UssdAccessibilityService? = null
        private val USSD_PKG = Regex("^com\\.android\\.phone$|telephony|telecom|ussd|mmi|\\.stk", RegexOption.IGNORE_CASE)
        private val EXCLUDED = Regex("launcher|systemui|mms|message|messaging|contacts|dialer|incallui|flashpay", RegexOption.IGNORE_CASE)
        private val RUNNING = Regex("ex[ée]cution|en cours|running|veuillez patienter|please wait|chargement|loading", RegexOption.IGNORE_CASE)
    }
}
