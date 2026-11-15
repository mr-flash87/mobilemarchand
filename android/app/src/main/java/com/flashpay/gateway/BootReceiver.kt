package com.flashpay.gateway

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Relance la passerelle après un redémarrage du téléphone ou une mise à jour de l'application. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val store = Store.get(context)
        if (!store.isPaired) return
        store.log("🔄 Redémarrage : passerelle relancée")
        GatewayService.start(context)
        Sync.schedulePeriodic(context)
        Sync.now(context)
    }
}
