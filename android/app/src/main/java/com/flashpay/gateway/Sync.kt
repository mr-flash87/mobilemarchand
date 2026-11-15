package com.flashpay.gateway

import android.content.Context
import androidx.work.BackoffPolicy
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

    fun now(context: Context) {
        val req = OneTimeWorkRequestBuilder<UploadWorker>()
            .setConstraints(network)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork("upload", ExistingWorkPolicy.APPEND_OR_REPLACE, req)
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
