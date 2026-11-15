package com.flashpay.gateway

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import java.security.MessageDigest

/**
 * Lit les notifications des applications de paiement (ex. Wave), uniquement celles configurées sur le site.
 * Android garantit l'application d'origine d'une notification : impossible de la falsifier par un simple SMS.
 * Les notifications des autres applications sont ignorées et ne quittent jamais le téléphone.
 */
class PaymentNotificationListener : NotificationListenerService() {

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        if (sbn == null) return
        val store = Store.get(this)
        if (!store.isPaired) return
        val app = store.notifAppFor(sbn.packageName) ?: return
        val n = sbn.notification ?: return
        // Résumés de groupe et notifications permanentes (« synchronisation… ») : rien à lire.
        if (n.flags and Notification.FLAG_GROUP_SUMMARY != 0) return
        if (n.flags and Notification.FLAG_ONGOING_EVENT != 0) return

        val text = extractText(n)
        if (text.isBlank()) return
        // Seules les notifications qui parlent d'argent reçu nous intéressent.
        if (!MONEY.containsMatchIn(text)) return

        if (!store.markSeen(sha1(sbn.packageName + "|" + text))) return
        if (store.enqueue(app.sender, text, sbn.postTime, "notification")) {
            store.log("📩 Notification ${app.sender} reçue")
            Sync.now(this)
            GatewayService.poke(this)
        }
    }

    private fun extractText(n: Notification): String {
        val e = n.extras ?: return ""
        val title = e.getCharSequence(Notification.EXTRA_TITLE)?.toString()?.trim().orEmpty()
        val big = e.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString()?.trim().orEmpty()
        val body = big.ifEmpty { e.getCharSequence(Notification.EXTRA_TEXT)?.toString()?.trim().orEmpty() }
        val lines = e.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.joinToString(" ") { it.toString() }.orEmpty()
        return listOf(title, body.ifEmpty { lines }).filter { it.isNotEmpty() }.joinToString(". ")
    }

    private fun sha1(s: String): String =
        MessageDigest.getInstance("SHA-1").digest(s.toByteArray()).joinToString("") { "%02x".format(it) }

    companion object {
        private val MONEY = Regex("(re[çc]u|recu|received|cr[ée]dit|paiement|payment)", RegexOption.IGNORE_CASE)

        /** L'utilisateur a-t-il autorisé la lecture des notifications pour cette application ? */
        fun isEnabled(context: Context): Boolean {
            val flat = Settings.Secure.getString(context.contentResolver, "enabled_notification_listeners") ?: return false
            val me = ComponentName(context, PaymentNotificationListener::class.java)
            return flat.split(":").any { ComponentName.unflattenFromString(it) == me }
        }
    }
}
