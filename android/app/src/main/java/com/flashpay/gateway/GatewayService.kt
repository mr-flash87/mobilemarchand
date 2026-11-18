package com.flashpay.gateway

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.os.PowerManager

/**
 * Service de premier plan : garde l'application active en permanence (notification fixe),
 * envoie un signal de vie au serveur toutes les 2 minutes et vide la file d'attente.
 */
class GatewayService : Service() {
    private lateinit var thread: HandlerThread
    private lateinit var handler: Handler
    private var wakeLock: PowerManager.WakeLock? = null

    private val tick = object : Runnable {
        override fun run() {
            work(heartbeat = true)
            handler.postDelayed(this, HEARTBEAT_MS)
        }
    }

    /** Envois d'argent : toutes les 15 s, demande au site s'il y a un envoi à faire. */
    private val payoutTick = object : Runnable {
        override fun run() {
            try {
                payoutCheck()
            } catch (e: Exception) {
                Store.get(this@GatewayService).log("❌ Envoi d'argent : ${e.message}")
            }
            handler.postDelayed(this, PAYOUT_MS)
        }
    }

    private fun payoutCheck() {
        val store = Store.get(this)
        if (!PayoutRunner.ready(this, store) || !store.payoutsServerEnabled) return
        // Un résultat pas encore transmis passe avant tout nouvel envoi (jamais deux envois sans compte rendu).
        store.pendingPayoutReport?.let {
            try {
                Api.reportPayout(store, org.json.JSONObject(it))
                store.pendingPayoutReport = null
            } catch (e: Exception) {
                return
            }
        }
        val job = try { Api.nextPayout(this, store, PayoutRunner.usableOperators(this, store)) } catch (e: Exception) { null } ?: return
        store.log("💸 Envoi de ${job.amount} F vers ${job.phone} (${job.opName})…")
        val (status, message, pinSent) = PayoutRunner.run(this, store, job)
        store.log(
            when (status) {
                "done" -> "📨 ${job.opName} : ${message.take(120)}"
                "failed" -> "❌ Envoi non fait : ${message.take(120)}"
                else -> "⚠️ Envoi à vérifier : ${message.take(120)}"
            }
        )
        val report = org.json.JSONObject().put("ref", job.ref).put("status", status).put("message", message).put("pin_sent", pinSent)
        try {
            Api.reportPayout(store, report)
        } catch (e: Exception) {
            store.pendingPayoutReport = report.toString()
        }
    }

    override fun onCreate() {
        super.onCreate()
        running = true
        thread = HandlerThread("gateway").also { it.start() }
        handler = Handler(thread.looper)
        startAsForeground()
        handler.post(tick)
        handler.postDelayed(payoutTick, 5_000)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_FLUSH) handler.post { work(heartbeat = false) }
        return START_STICKY
    }

    private fun work(heartbeat: Boolean) {
        val store = Store.get(this)
        if (!store.isPaired) return
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        val wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "gateway:sync").also { it.acquire(60_000) }
        try {
            if (store.pendingCount() > 0) Api.flush(this, store)
            if (heartbeat) Api.heartbeat(this, store)
            updateNotification()
        } catch (e: Exception) {
            store.lastError = e.message ?: "Erreur réseau"
            Sync.now(this) // WorkManager prendra le relais avec réessais
            updateNotification()
        } finally {
            if (wl.isHeld) wl.release()
        }
    }

    private fun startAsForeground() {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= 26) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Passerelle SMS", NotificationManager.IMPORTANCE_LOW))
        }
        val n = buildNotification()
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIF_ID, n)
        }
    }

    private fun buildNotification(): Notification {
        val store = Store.get(this)
        val text = when {
            !store.isPaired -> "Non associé au serveur"
            store.lastError.isNotEmpty() -> "⚠️ ${store.lastError}"
            else -> "Active · ${store.pendingCount()} SMS en attente d'envoi"
        }
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        @Suppress("DEPRECATION")
        val b = if (Build.VERSION.SDK_INT >= 26) Notification.Builder(this, CHANNEL) else Notification.Builder(this)
        return b.setContentTitle("Passerelle de paiement")
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_notification)
            .setOngoing(true)
            .setContentIntent(open)
            .build()
    }

    private fun updateNotification() {
        getSystemService(NotificationManager::class.java).notify(NOTIF_ID, buildNotification())
    }

    override fun onDestroy() {
        running = false
        handler.removeCallbacksAndMessages(null)
        thread.quitSafely()
        wakeLock?.release()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        private const val CHANNEL = "gateway"
        private const val NOTIF_ID = 1
        private const val HEARTBEAT_MS = 120_000L
        private const val PAYOUT_MS = 15_000L
        private const val ACTION_FLUSH = "flush"
        @Volatile var running = false

        fun start(context: Context) {
            val i = Intent(context, GatewayService::class.java)
            try {
                if (Build.VERSION.SDK_INT >= 26) context.startForegroundService(i) else context.startService(i)
            } catch (e: Exception) {
                // Démarrage refusé en arrière-plan (Android 12+) : WorkManager assure l'envoi.
                Store.get(context).log("Service non démarré : ${e.javaClass.simpleName}")
            }
        }

        /** Demande un envoi immédiat si le service tourne déjà. */
        fun poke(context: Context) {
            if (!running) return
            try {
                context.startService(Intent(context, GatewayService::class.java).setAction(ACTION_FLUSH))
            } catch (_: Exception) {
            }
        }
    }
}
