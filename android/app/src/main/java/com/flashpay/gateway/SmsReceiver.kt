package com.flashpay.gateway

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony

/**
 * Reçoit chaque SMS entrant. Seuls les SMS des expéditeurs autorisés (opérateurs Mobile Money)
 * sont mis en file et transmis : les SMS personnels ne quittent jamais le téléphone.
 */
class SmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        val parts = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        if (parts.isEmpty()) return
        val store = Store.get(context)

        // Un long SMS arrive en plusieurs morceaux : on les recolle par expéditeur.
        parts.groupBy { it.originatingAddress ?: it.displayOriginatingAddress ?: "" }.forEach { (sender, msgs) ->
            val body = msgs.joinToString("") { it.messageBody ?: it.displayMessageBody ?: "" }
            val ts = msgs.first().timestampMillis.takeIf { it > 0 } ?: System.currentTimeMillis()
            if (sender.isNotEmpty() && store.isAllowedSender(sender)) {
                if (store.enqueue(sender, body, ts)) store.log("📩 SMS reçu de $sender")
            }
        }
        if (store.isPaired) {
            // goAsync : Android garde le récepteur en vie le temps de l'envoi (même écran éteint).
            val pending = goAsync()
            Sync.sendNow(context) { pending.finish() }
        }
    }
}
