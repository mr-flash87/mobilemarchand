package com.flashpay.gateway

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONArray
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.regex.Pattern

data class QueuedSms(val id: Long, val sender: String, val body: String, val timestamp: Long, val source: String = "sms")

/** Application dont on lit les notifications (ex. Wave) : motif du nom de paquet + nom envoyé comme expéditeur. */
data class NotifApp(val packagePattern: String, val sender: String)

data class LogEntry(val timestamp: Long, val line: String)

/** Réglages + file d'attente locale des SMS à envoyer (survit aux redémarrages et coupures réseau). */
class Store private constructor(context: Context) :
    SQLiteOpenHelper(context.applicationContext, "gateway.db", null, 2) {

    private val prefs = context.applicationContext.getSharedPreferences("gateway", Context.MODE_PRIVATE)

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            "CREATE TABLE queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL, body TEXT NOT NULL, " +
                "ts INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT 'sms', UNIQUE(sender, body, ts))"
        )
        db.execSQL("CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, line TEXT NOT NULL)")
        db.execSQL("CREATE TABLE seen (hash TEXT PRIMARY KEY, ts INTEGER NOT NULL)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 2) {
            db.execSQL("ALTER TABLE queue ADD COLUMN source TEXT NOT NULL DEFAULT 'sms'")
            db.execSQL("CREATE TABLE IF NOT EXISTS seen (hash TEXT PRIMARY KEY, ts INTEGER NOT NULL)")
        }
    }

    // ---- Réglages ----
    var serverUrl: String
        get() = prefs.getString("server_url", "") ?: ""
        set(v) = prefs.edit().putString("server_url", v.trim().trimEnd('/')).apply()

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(v) = prefs.edit().putString("token", v).apply()

    var deviceName: String
        get() = prefs.getString("device_name", "") ?: ""
        set(v) = prefs.edit().putString("device_name", v).apply()

    var lastSyncAt: Long
        get() = prefs.getLong("last_sync", 0)
        set(v) = prefs.edit().putLong("last_sync", v).apply()

    var lastError: String
        get() = prefs.getString("last_error", "") ?: ""
        set(v) = prefs.edit().putString("last_error", v).apply()

    /** Expéditeurs autorisés (regex) reçus du serveur. Vide = on n'envoie rien tant que non associé. */
    var allowedSenders: List<String>
        get() {
            val raw = prefs.getString("allowed_senders", null) ?: return DEFAULT_SENDERS
            return try {
                val arr = JSONArray(raw)
                List(arr.length()) { arr.getString(it) }
            } catch (e: Exception) {
                DEFAULT_SENDERS
            }
        }
        set(v) = prefs.edit().putString("allowed_senders", JSONArray(v).toString()).apply()

    /** Applications dont on transmet les notifications de paiement (configuré depuis le site). */
    var notificationApps: List<NotifApp>
        get() {
            val raw = prefs.getString("notification_apps", null) ?: return DEFAULT_NOTIF_APPS
            return try {
                val arr = JSONArray(raw)
                List(arr.length()) {
                    val o = arr.getJSONObject(it)
                    NotifApp(o.getString("package"), o.optString("sender", "App"))
                }
            } catch (e: Exception) {
                DEFAULT_NOTIF_APPS
            }
        }
        set(v) {
            val arr = JSONArray()
            v.forEach { arr.put(org.json.JSONObject().put("package", it.packagePattern).put("sender", it.sender)) }
            prefs.edit().putString("notification_apps", arr.toString()).apply()
        }

    // ---- Envois d'argent ----
    /** Activé sur ce téléphone par l'utilisateur (désactivé par défaut). */
    var payoutsEnabled: Boolean
        get() = prefs.getBoolean("payouts_enabled", false)
        set(v) = prefs.edit().putBoolean("payouts_enabled", v).apply()

    /** Opérateurs capables d'envoyer, configurés sur le site. */
    var payoutOperators: List<PayoutOp>
        get() = try {
            val arr = JSONArray(prefs.getString("payout_ops", "[]"))
            List(arr.length()) { val o = arr.getJSONObject(it); PayoutOp(o.getString("code"), o.optString("name"), o.optString("ussd")) }
        } catch (e: Exception) {
            emptyList()
        }
        set(v) {
            val arr = JSONArray()
            v.forEach { arr.put(org.json.JSONObject().put("code", it.code).put("name", it.name).put("ussd", it.ussd)) }
            prefs.edit().putString("payout_ops", arr.toString()).apply()
        }

    var payoutsServerEnabled: Boolean
        get() = prefs.getBoolean("payouts_server", true)
        set(v) = prefs.edit().putBoolean("payouts_server", v).apply()

    /** Envoi par application (Wave) activé sur ce téléphone. */
    fun appPayoutOn(op: String): Boolean = prefs.getBoolean("app_payout_$op", false)
    fun setAppPayout(op: String, on: Boolean) = prefs.edit().putBoolean("app_payout_$op", on).apply()

    /** SIM à utiliser pour un opérateur : -1 = automatique, 0 = SIM 1, 1 = SIM 2. */
    fun simFor(op: String): Int = prefs.getInt("sim_$op", -1)
    fun setSim(op: String, slot: Int) = prefs.edit().putInt("sim_$op", slot).apply()

    /** Résultat d'envoi pas encore transmis au site (coupure réseau) : renvoyé avant tout nouvel envoi. */
    var pendingPayoutReport: String?
        get() = prefs.getString("payout_report", null)
        set(v) = prefs.edit().putString("payout_report", v).apply()

    fun notifAppFor(packageName: String): NotifApp? = notificationApps.firstOrNull { app ->
        try {
            Pattern.compile(app.packagePattern, Pattern.CASE_INSENSITIVE).matcher(packageName).find()
        } catch (e: Exception) {
            false
        }
    }

    /** Anti-doublon : une même notification mise à jour plusieurs fois n'est transmise qu'une fois (fenêtre 30 min). */
    fun markSeen(hash: String): Boolean {
        val now = System.currentTimeMillis()
        val db = writableDatabase
        db.execSQL("DELETE FROM seen WHERE ts < ?", arrayOf<Any>(now - 30 * 60 * 1000L))
        val cv = ContentValues().apply { put("hash", hash); put("ts", now) }
        return db.insertWithOnConflict("seen", null, cv, SQLiteDatabase.CONFLICT_IGNORE) != -1L
    }

    val isPaired: Boolean get() = serverUrl.isNotEmpty() && token.isNotEmpty()

    fun isAllowedSender(sender: String): Boolean = allowedSenders.any { rx ->
        try {
            Pattern.compile(rx, Pattern.CASE_INSENSITIVE or Pattern.UNICODE_CASE).matcher(sender).find()
        } catch (e: Exception) {
            false
        }
    }

    // ---- File d'attente ----
    fun enqueue(sender: String, body: String, timestamp: Long, source: String = "sms"): Boolean {
        val cv = ContentValues().apply {
            put("sender", sender)
            put("body", body)
            put("ts", timestamp)
            put("source", source)
        }
        return writableDatabase.insertWithOnConflict("queue", null, cv, SQLiteDatabase.CONFLICT_IGNORE) != -1L
    }

    fun pending(limit: Int = 50): List<QueuedSms> {
        val out = mutableListOf<QueuedSms>()
        readableDatabase.rawQuery("SELECT id, sender, body, ts, source FROM queue ORDER BY id LIMIT $limit", null).use { c ->
            while (c.moveToNext()) out += QueuedSms(c.getLong(0), c.getString(1), c.getString(2), c.getLong(3), c.getString(4) ?: "sms")
        }
        return out
    }

    fun pendingCount(): Long = android.database.DatabaseUtils.queryNumEntries(readableDatabase, "queue")

    fun remove(ids: Collection<Long>) {
        if (ids.isEmpty()) return
        writableDatabase.execSQL("DELETE FROM queue WHERE id IN (${ids.joinToString(",")})")
    }

    // ---- Journal affiché dans l'application ----
    fun log(line: String) {
        val db = writableDatabase
        db.insert("log", null, ContentValues().apply {
            put("ts", System.currentTimeMillis())
            put("line", line)
        })
        db.execSQL("DELETE FROM log WHERE id NOT IN (SELECT id FROM log ORDER BY id DESC LIMIT 60)")
    }

    fun recentLog(): String {
        val fmt = SimpleDateFormat("dd/MM HH:mm:ss", Locale.FRANCE)
        val sb = StringBuilder()
        readableDatabase.rawQuery("SELECT ts, line FROM log ORDER BY id DESC LIMIT 30", null).use { c ->
            while (c.moveToNext()) sb.append(fmt.format(Date(c.getLong(0)))).append("  ").append(c.getString(1)).append('\n')
        }
        return sb.toString()
    }

    fun recentEntries(limit: Int = 40): List<LogEntry> {
        val out = mutableListOf<LogEntry>()
        readableDatabase.rawQuery("SELECT ts, line FROM log ORDER BY id DESC LIMIT $limit", null).use { c ->
            while (c.moveToNext()) out += LogEntry(c.getLong(0), c.getString(1))
        }
        return out
    }

    // ---- Compteurs du jour (remis à zéro chaque jour) ----
    private fun todayKey(): String = SimpleDateFormat("yyyyMMdd", Locale.US).format(Date())

    fun bumpToday(name: String, by: Int = 1) {
        val key = "stat_${name}_${todayKey()}"
        prefs.edit().putInt(key, prefs.getInt(key, 0) + by).apply()
    }

    fun today(name: String): Int = prefs.getInt("stat_${name}_${todayKey()}", 0)

    companion object {
        val DEFAULT_SENDERS = listOf("orange|^OM$", "moov|flooz")
        val DEFAULT_NOTIF_APPS = listOf(NotifApp("^com\\.wave\\.", "Wave"))

        @Volatile private var instance: Store? = null
        fun get(context: Context): Store = instance ?: synchronized(this) {
            instance ?: Store(context).also { instance = it }
        }
    }
}
