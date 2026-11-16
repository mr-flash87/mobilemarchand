package com.flashpay.gateway

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import android.os.PowerManager
import androidx.work.BackoffPolicy
import androidx.work.ForegroundInfo
import androidx.work.OutOfQuotaPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

/**
 * Envoi fiable via WorkManager : réessaie automatiquement (backoff) tant que le réseau
 * ou le serveur est indisponible, même si l'application a été fermée.
 */
class UploadWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {

    /** Requis pour les tâches prioritaires sur Android 11 et moins (exécutées en premier plan). */
    override suspend fun getForegroundInfo(): ForegroundInfo {
        val nm = applicationContext.getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= 26) {
            nm.createNotificationChannel(NotificationChannel("sync", "Envoi des paiements", NotificationManager.IMPORTANCE_MIN))
        }
        @Suppress("DEPRECATION")
        val b = if (Build.VERSION.SDK_INT >= 26) Notification.Builder(applicationContext, "sync") else Notification.Builder(applicationContext)
        val n = b.setContentTitle("Envoi d'un paiement…").setSmallIcon(R.drawable.ic_notification).build()
        return ForegroundInfo(42, n)
    }

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        val store = Store.get(applicationContext)
        if (!store.isPaired) return@withContext Result.success()
        try {
            Api.flush(applicationContext, store)
            if (inputData.getBoolean("heartbeat", false)) Api.heartbeat(applicationContext, store)
            Result.success()
        } catch (e: ApiException) {
            store.lastError = e.message ?: "Erreur serveur"
            store.log("❌ ${store.lastError}")
            if (e.httpCode == 401) Result.failure() else Result.retry()
        } catch (e: Exception) {
            store.lastError = "Réseau : ${e.message}"
            Result.retry()
        }
    }
}

object Sync {
    private val network = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    /**
     * Tâche PRIORITAIRE (« expedited ») : Android l'exécute tout de suite, même téléphone verrouillé
     * et en veille profonde (Doze), au lieu d'attendre le déverrouillage.
     */
    fun now(context: Context) {
        val req = OneTimeWorkRequestBuilder<UploadWorker>()
            .setConstraints(network)
            .setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 10, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork("upload", ExistingWorkPolicy.APPEND_OR_REPLACE, req)
    }

    /**
     * Envoi IMMÉDIAT dès qu'un SMS ou une notification de paiement arrive : on réveille le processeur
     * quelques secondes et on envoie directement. En cas d'échec, la tâche prioritaire prend le relais.
     * [onDone] est appelé à la fin (utile pour terminer un BroadcastReceiver).
     */
    fun sendNow(context: Context, onDone: (() -> Unit)? = null) {
        val app = context.applicationContext
        val pm = app.getSystemService(Context.POWER_SERVICE) as PowerManager
        val wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "gateway:send").apply { acquire(25_000) }
        Thread {
            val store = Store.get(app)
            try {
                if (store.isPaired && store.pendingCount() > 0) {
                    Api.flush(app, store)
                    store.lastError = ""
                }
            } catch (e: Exception) {
                store.lastError = "Réseau : ${e.message}"
                now(app)
            } finally {
                if (wl.isHeld) wl.release()
                onDone?.invoke()
            }
        }.start()
    }

    /** Filet de sécurité : toutes les 15 min, renvoie la file et signale que le téléphone est vivant. */
    fun schedulePeriodic(context: Context) {
        val req = PeriodicWorkRequestBuilder<UploadWorker>(15, TimeUnit.MINUTES)
            .setConstraints(network)
            .setInputData(androidx.work.workDataOf("heartbeat" to true))
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork("periodic", ExistingPeriodicWorkPolicy.UPDATE, req)
    }
}
