package com.flashpay.gateway

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

class ApiException(val httpCode: Int, message: String) : IOException(message)

/** Appels HTTP vers le serveur (bloquants : à appeler hors du thread principal). */
object Api {
    private val VERSION = BuildConfig.VERSION_NAME

    private fun post(url: String, token: String?, body: JSONObject): JSONObject {
        val conn = URL(url).openConnection() as HttpURLConnection
        try {
            conn.requestMethod = "POST"
            conn.connectTimeout = 15000
            conn.readTimeout = 20000
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.setRequestProperty("Accept", "application/json")
            conn.setRequestProperty("User-Agent", "SmsPayGateway/$VERSION")
            if (token != null) {
                conn.setRequestProperty("Authorization", "Bearer $token")
                conn.setRequestProperty("X-Api-Key", token)
            }
            conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: ""
            val json = try { JSONObject(text) } catch (e: Exception) { JSONObject() }
            if (code !in 200..299) {
                val msg = json.optJSONObject("error")?.optString("message") ?: "HTTP $code"
                throw ApiException(code, msg)
            }
            return json
        } finally {
            conn.disconnect()
        }
    }

    private fun applyConfig(store: Store, json: JSONObject) {
        val cfg = json.optJSONObject("config") ?: return
        cfg.optJSONArray("allowed_senders")?.let { arr ->
            store.allowedSenders = List(arr.length()) { arr.getString(it) }
        }
        cfg.optJSONArray("notification_apps")?.let { arr ->
            store.notificationApps = List(arr.length()) {
                val o = arr.getJSONObject(it)
                NotifApp(o.getString("package"), o.optString("sender", "App"))
            }
        }
    }

    fun pair(store: Store, serverUrl: String, code: String) {
        val base = serverUrl.trim().trimEnd('/')
        val res = post("$base/api/device/pair", null, JSONObject().put("code", code).put("app_version", VERSION))
        store.serverUrl = base
        store.token = res.getString("token")
        store.deviceName = res.optJSONObject("device")?.optString("name") ?: ""
        applyConfig(store, res)
    }

    fun heartbeat(context: Context, store: Store) {
        val res = post("${store.serverUrl}/api/device/heartbeat", store.token, deviceInfo(context))
        applyConfig(store, res)
        store.lastSyncAt = System.currentTimeMillis()
    }

    /** Envoie la file d'attente. Retourne le nombre de SMS transmis. */
    fun flush(context: Context, store: Store): Int {
        var sent = 0
        while (true) {
            val batch = store.pending(50)
            if (batch.isEmpty()) break
            val messages = JSONArray()
            batch.forEach {
                messages.put(JSONObject().put("client_id", it.id).put("sender", it.sender).put("body", it.body).put("timestamp", it.timestamp).put("source", it.source))
            }
            val res = post("${store.serverUrl}/api/device/sms", store.token, deviceInfo(context).put("messages", messages))
            val results = res.optJSONArray("results") ?: JSONArray()
            for (i in 0 until results.length()) {
                val r = results.getJSONObject(i)
                val status = r.optString("status")
                if (status != "already_received") store.bumpToday("sent")
                if (status == "matched") store.bumpToday("matched")
                val payment = r.optString("payment").takeIf { it.isNotEmpty() && it != "null" }
                store.log(
                    when (status) {
                        "matched" -> "✅ Paiement confirmé $payment"
                        "unmatched" -> "⚠️ Argent reçu sans paiement correspondant"
                        "review" -> "🔎 SMS à vérifier (solde incohérent)"
                        "duplicate" -> "♻️ Doublon ignoré"
                        "already_received" -> "♻️ Déjà transmis"
                        "info" -> "ℹ️ Solde mis à jour"
                        else -> "SMS transmis ($status)"
                    }
                )
            }
            store.remove(batch.map { it.id })
            sent += batch.size
            store.lastSyncAt = System.currentTimeMillis()
        }
        store.lastError = ""
        return sent
    }

    private fun deviceInfo(context: Context): JSONObject {
        val o = JSONObject().put("app_version", VERSION)
        try {
            val bat = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            if (bat != null) {
                val level = bat.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                val scale = bat.getIntExtra(BatteryManager.EXTRA_SCALE, 100)
                val plugged = bat.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0)
                if (level >= 0 && scale > 0) o.put("battery", level * 100 / scale)
                o.put("charging", plugged != 0)
            }
        } catch (_: Exception) {
        }
        return o
    }
}
