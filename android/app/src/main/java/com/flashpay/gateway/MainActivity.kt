package com.flashpay.gateway

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.provider.Telephony
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Badge
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Home
import androidx.compose.foundation.clickable
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Email
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Photo de l'état de la passerelle, rafraîchie toutes les 2 secondes. */
data class UiState(
    val paired: Boolean = false,
    val deviceName: String = "",
    val serverUrl: String = "",
    val lastSync: Long = 0,
    val lastError: String = "",
    val pending: Long = 0,
    val sentToday: Int = 0,
    val matchedToday: Int = 0,
    val smsPermission: Boolean = false,
    val notifPermission: Boolean = true,
    val batteryOk: Boolean = false,
    val notifAccess: Boolean = false,
    val serviceOn: Boolean = false,
    val entries: List<LogEntry> = emptyList(),
    val payoutOps: List<PayoutOp> = emptyList(),
    val payoutPins: Set<String> = emptySet(),
    val payoutsOn: Boolean = false,
    val payoutsServer: Boolean = true,
    val accessibilityOn: Boolean = false,
    val callPermission: Boolean = false,
    val phoneStatePermission: Boolean = false,
)

class MainActivity : ComponentActivity() {
    lateinit var store: Store

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        store = Store.get(this)
        if (store.isPaired) {
            GatewayService.start(this)
            Sync.schedulePeriodic(this)
        }
        setContent { GatewayTheme { GatewayApp(this) } }
    }

    fun has(p: String) = checkSelfPermission(p) == PackageManager.PERMISSION_GRANTED

    fun readState(): UiState {
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        return UiState(
            paired = store.isPaired,
            deviceName = store.deviceName,
            serverUrl = store.serverUrl,
            lastSync = store.lastSyncAt,
            lastError = store.lastError,
            pending = store.pendingCount(),
            sentToday = store.today("sent"),
            matchedToday = store.today("matched"),
            smsPermission = has(Manifest.permission.RECEIVE_SMS),
            notifPermission = Build.VERSION.SDK_INT < 33 || has(Manifest.permission.POST_NOTIFICATIONS),
            batteryOk = pm.isIgnoringBatteryOptimizations(packageName),
            notifAccess = PaymentNotificationListener.isEnabled(this),
            serviceOn = GatewayService.running,
            entries = store.recentEntries(40),
            payoutOps = store.payoutOperators,
            payoutPins = store.payoutOperators.filter { PinVault.has(this, it.code) }.map { it.code }.toSet(),
            payoutsOn = store.payoutsEnabled,
            payoutsServer = store.payoutsServerEnabled,
            accessibilityOn = PayoutRunner.accessibilityOn(this),
            callPermission = has(Manifest.permission.CALL_PHONE),
            phoneStatePermission = has(Manifest.permission.READ_PHONE_STATE),
        )
    }

    fun askPayoutPermissions() {
        val missing = listOf(Manifest.permission.CALL_PHONE, Manifest.permission.READ_PHONE_STATE).filterNot { has(it) }
        if (missing.isNotEmpty()) requestPermissions(missing.toTypedArray(), 42) else openAppSettings()
    }

    fun openAccessibility() {
        try {
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        } catch (e: Exception) {
            openAppSettings()
        }
    }

    fun neededPermissions(): Array<String> {
        val wanted = mutableListOf(Manifest.permission.RECEIVE_SMS, Manifest.permission.READ_SMS)
        if (Build.VERSION.SDK_INT >= 33) wanted += Manifest.permission.POST_NOTIFICATIONS
        return wanted.filterNot { has(it) }.toTypedArray()
    }

    @SuppressLint("BatteryLife")
    fun openBatterySettings() {
        try {
            startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")))
        } catch (e: Exception) {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }

    fun openNotificationAccess() {
        try {
            startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
        } catch (e: Exception) {
            openAppSettings()
        }
    }

    fun openAppSettings() {
        startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
    }

    fun startGateway() {
        GatewayService.start(this)
        Sync.schedulePeriodic(this)
    }

    fun unpair() {
        store.token = ""
        stopService(Intent(this, GatewayService::class.java))
        store.log("Téléphone dissocié")
    }

    /** Remet en file les SMS des dernières 24 h (le serveur ignore les doublons). Appel hors thread principal. */
    fun requeueInbox(): Int {
        if (!has(Manifest.permission.READ_SMS)) return -1
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
        return count
    }
}

/* =========================================================================================
 *  Écrans
 * ========================================================================================= */

@Composable
fun GatewayApp(activity: MainActivity) {
    var state by remember { mutableStateOf(activity.readState()) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        state = activity.readState()
        if (state.paired) activity.startGateway()
    }
    LaunchedEffect(Unit) {
        val missing = activity.neededPermissions()
        if (missing.isNotEmpty()) permLauncher.launch(missing)
        while (true) {
            state = activity.readState()
            delay(2000)
        }
    }
    val askPermissions = {
        val missing = activity.neededPermissions()
        if (missing.isNotEmpty()) permLauncher.launch(missing) else activity.openAppSettings()
    }
    val toast: (String) -> Unit = { msg -> scope.launch { snackbar.showSnackbar(msg) } }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        containerColor = MaterialTheme.colorScheme.background,
    ) { pad ->
        if (!state.paired) {
            PairScreen(activity, state, pad, askPermissions, toast) { state = activity.readState() }
        } else {
            HomeScreen(activity, state, pad, askPermissions, toast) { state = activity.readState() }
        }
    }
}

@Composable
private fun HeaderBand(title: String, subtitle: String, trailing: @Composable () -> Unit = {}) {
    Box(
        Modifier
            .fillMaxWidth()
            .background(Brush.linearGradient(listOf(Brand.Navy, Color(0xFF13306E), Brand.Blue)))
            .statusBarsPadding()
            .padding(start = 20.dp, end = 8.dp, top = 14.dp, bottom = 26.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(44.dp).clip(RoundedCornerShape(13.dp)).background(Color.White.copy(alpha = 0.14f)),
                contentAlignment = Alignment.Center
            ) { Icon(Icons.Filled.Email, null, tint = Color.White, modifier = Modifier.size(24.dp)) }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 19.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(subtitle, color = Color.White.copy(alpha = 0.72f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            trailing()
        }
    }
}

@Composable
private fun SectionCard(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) { Column(Modifier.padding(20.dp)) { content() } }
}

@Composable
private fun CardTitle(text: String) {
    Text(text, fontWeight = FontWeight.Bold, fontSize = 18.sp, color = MaterialTheme.colorScheme.onSurface)
    Spacer(Modifier.height(12.dp))
}

/* ---------------------------------- Association ---------------------------------- */

@Composable
fun PairScreen(
    activity: MainActivity, state: UiState, pad: PaddingValues,
    askPermissions: () -> Unit, toast: (String) -> Unit, refresh: () -> Unit,
) {
    var url by remember { mutableStateOf(activity.store.serverUrl) }
    var code by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()

    LazyColumn(
        Modifier.fillMaxSize().padding(bottom = pad.calculateBottomPadding()),
        contentPadding = PaddingValues(bottom = 32.dp),
    ) {
        item { HeaderBand("Passerelle de paiement", "Confirmation automatique des paiements Mobile Money") }
        item {
            Column(Modifier.padding(horizontal = 16.dp).padding(top = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                SectionCard {
                    Text("Associer ce téléphone", fontWeight = FontWeight.Bold, fontSize = 20.sp)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "Dans votre espace marchand, ouvrez Téléphones → Ajouter un téléphone : un code à 6 chiffres s'affiche.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 14.sp
                    )
                    Spacer(Modifier.height(16.dp))
                    OutlinedTextField(
                        value = url, onValueChange = { url = it; error = "" },
                        label = { Text("Adresse du site") },
                        placeholder = { Text("https://marchand.monsite.com/pay") },
                        singleLine = true, modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                        shape = MaterialTheme.shapes.medium,
                    )
                    Spacer(Modifier.height(12.dp))
                    OutlinedTextField(
                        value = code,
                        onValueChange = { v -> code = v.filter { it.isDigit() }.take(6); error = "" },
                        label = { Text("Code d'association") },
                        placeholder = { Text("000000") },
                        singleLine = true, modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                        textStyle = MaterialTheme.typography.headlineSmall.copy(
                            fontFamily = FontFamily.Monospace, letterSpacing = 8.sp, textAlign = TextAlign.Center
                        ),
                        shape = MaterialTheme.shapes.medium,
                    )
                    if (error.isNotEmpty()) {
                        Spacer(Modifier.height(10.dp))
                        Notice(error, Brand.Red, Brand.RedSoft, Icons.Filled.Warning)
                    }
                    Spacer(Modifier.height(16.dp))
                    Button(
                        onClick = {
                            var u = url.trim()
                            if (u.isEmpty() || code.length != 6) {
                                error = "Saisissez l'adresse du site et le code à 6 chiffres."
                                return@Button
                            }
                            if (!u.startsWith("http")) u = "https://$u"
                            loading = true
                            scope.launch {
                                try {
                                    withContext(Dispatchers.IO) { Api.pair(activity.store, u, code) }
                                    activity.store.lastError = ""
                                    activity.store.log("🔗 Associé au serveur $u")
                                    activity.startGateway()
                                    toast("Téléphone associé ✅")
                                    refresh()
                                } catch (e: Exception) {
                                    error = e.message ?: "Échec de l'association"
                                } finally {
                                    loading = false
                                }
                            }
                        },
                        enabled = !loading,
                        modifier = Modifier.fillMaxWidth().height(54.dp),
                        shape = MaterialTheme.shapes.medium,
                    ) {
                        if (loading) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                        else Text("Associer ce téléphone", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                    }
                }
                ChecklistCard(activity, state, askPermissions)
                PrivacyNote()
            }
        }
    }
}

/* ---------------------------------- Accueil ---------------------------------- */

@Composable
fun HomeScreen(
    activity: MainActivity, state: UiState, pad: PaddingValues,
    askPermissions: () -> Unit, toast: (String) -> Unit, refresh: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(0) }
    var syncing by remember { mutableStateOf(false) }
    var confirmUnpair by remember { mutableStateOf(false) }

    val syncNow: () -> Unit = {
        syncing = true
        scope.launch {
            try {
                val n = withContext(Dispatchers.IO) {
                    val sent = Api.flush(activity, activity.store)
                    Api.heartbeat(activity, activity.store)
                    sent
                }
                activity.store.lastError = ""
                toast(if (n > 0) "Connexion OK · $n SMS envoyés" else "Connexion OK ✅")
            } catch (e: Exception) {
                activity.store.lastError = e.message ?: "Erreur réseau"
                toast("Erreur : ${e.message}")
            } finally {
                syncing = false
                refresh()
            }
        }
    }
    val requeue: () -> Unit = {
        scope.launch {
            val n = withContext(Dispatchers.IO) { activity.requeueInbox() }
            toast(if (n < 0) "Autorisez d'abord la lecture des SMS" else "$n SMS remis en file (les doublons sont ignorés)")
            refresh()
        }
    }
    val todo = listOf(state.smsPermission, state.notifPermission, state.notifAccess, state.batteryOk, state.serviceOn).count { !it }
    val titles = listOf("Tableau de bord", "Envois d'argent", "Journal", "Réglages")

    Column(Modifier.fillMaxSize().padding(bottom = pad.calculateBottomPadding())) {
        LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(bottom = 24.dp)) {
            item {
                TopBar(titles[tab], state.deviceName.ifEmpty { hostOf(state.serverUrl) }) {
                    RoundIcon(onClick = syncNow, enabled = !syncing) {
                        if (syncing) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                        else Icon(Icons.Filled.Refresh, "Synchroniser", tint = MaterialTheme.colorScheme.onSurface)
                    }
                }
            }
            item {
                Column(Modifier.padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    when (tab) {
                        0 -> {
                            StatusCard(state)
                            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                BigStat("Paiements confirmés", state.matchedToday.toString(), "aujourd'hui", Icons.Filled.CheckCircle, Modifier.weight(1f), Brand.Green)
                                BigStat("SMS transmis", state.sentToday.toString(), "aujourd'hui", Icons.Filled.Email, Modifier.weight(1f))
                            }
                            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                BigStat("En attente", state.pending.toString(), "d'envoi au site", Icons.Filled.Refresh, Modifier.weight(1f), if (state.pending > 0) Brand.Amber else null)
                                BigStat(
                                    "Envois d'argent", if (state.payoutsOn) "Actifs" else "Inactifs",
                                    if (state.payoutsOn) "${state.payoutPins.size} opérateur(s)" else "toucher pour activer",
                                    Icons.Filled.Send, Modifier.weight(1f).clickableNoRipple { tab = 1 },
                                    if (state.payoutsOn) Brand.Blue else null,
                                )
                            }
                            if (todo > 0) {
                                ActionBanner(
                                    "$todo réglage(s) à faire", "Indispensable pour confirmer les paiements automatiquement.",
                                    "Régler"
                                ) { tab = 3 }
                            }
                            ActivityCard(state.entries.take(6), onMore = { tab = 2 })
                        }
                        1 -> PayoutCard(activity, state, toast, refresh)
                        2 -> ActivityCard(state.entries, onMore = null)
                        else -> {
                            ChecklistCard(activity, state, askPermissions)
                            SectionCard {
                                CardTitle("Ce téléphone")
                                InfoLine("Nom", state.deviceName.ifEmpty { "—" })
                                InfoLine("Site", hostOf(state.serverUrl))
                                InfoLine("Dernier contact", if (state.lastSync > 0) relative(state.lastSync) else "—")
                                InfoLine("Version", BuildConfig.VERSION_NAME)
                                Spacer(Modifier.height(12.dp))
                                OutlinedButton(onClick = syncNow, modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium) { Text("Tester la connexion") }
                                Spacer(Modifier.height(8.dp))
                                OutlinedButton(onClick = requeue, modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium) { Text("Renvoyer les SMS des dernières 24 h") }
                                Spacer(Modifier.height(8.dp))
                                OutlinedButton(onClick = { activity.openAppSettings() }, modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium) { Text("Paramètres Android de l'application") }
                                Spacer(Modifier.height(8.dp))
                                TextButton(onClick = { confirmUnpair = true }, modifier = Modifier.fillMaxWidth()) { Text("Dissocier ce téléphone", color = Brand.Red) }
                            }
                            PrivacyNote()
                        }
                    }
                }
            }
        }
        NavigationBar(containerColor = MaterialTheme.colorScheme.surface, tonalElevation = 0.dp) {
            listOf(
                Triple("Accueil", Icons.Filled.Home, 0),
                Triple("Envois", Icons.Filled.Send, 1),
                Triple("Journal", Icons.Filled.List, 2),
                Triple("Réglages", Icons.Filled.Settings, 3),
            ).forEach { (label, icon, i) ->
                NavigationBarItem(
                    selected = tab == i, onClick = { tab = i },
                    icon = {
                        if (i == 3 && todo > 0) BadgedBox(badge = { Badge { Text(todo.toString()) } }) { Icon(icon, label) }
                        else Icon(icon, label)
                    },
                    label = { Text(label, fontSize = 12.sp) },
                )
            }
        }
    }

    if (confirmUnpair) {
        AlertDialog(
            onDismissRequest = { confirmUnpair = false },
            title = { Text("Dissocier ce téléphone ?") },
            text = { Text("Il n'enverra plus les SMS de paiement. Il faudra un nouveau code pour le réassocier.") },
            confirmButton = {
                TextButton(onClick = { confirmUnpair = false; activity.unpair(); refresh() }) { Text("Dissocier", color = Brand.Red) }
            },
            dismissButton = { TextButton(onClick = { confirmUnpair = false }) { Text("Annuler") } },
        )
    }
}

/** Barre du haut claire, grand titre (style tableau de bord). */
@Composable
private fun TopBar(title: String, subtitle: String, actions: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().statusBarsPadding().padding(start = 20.dp, end = 16.dp, top = 12.dp, bottom = 16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(38.dp).clip(RoundedCornerShape(11.dp)).background(Brand.Blue),
                contentAlignment = Alignment.Center
            ) { Icon(Icons.Filled.Email, null, tint = Color.White, modifier = Modifier.size(20.dp)) }
            Spacer(Modifier.width(10.dp))
            Text("Passerelle Pay", fontWeight = FontWeight.Bold, fontSize = 18.sp, modifier = Modifier.weight(1f))
            actions()
        }
        Spacer(Modifier.height(18.dp))
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 30.sp, color = MaterialTheme.colorScheme.onBackground)
        Text(subtitle, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun RoundIcon(onClick: () -> Unit, enabled: Boolean = true, content: @Composable () -> Unit) {
    Box(
        Modifier.size(46.dp).clip(CircleShape).background(MaterialTheme.colorScheme.surface)
            .border(1.dp, MaterialTheme.colorScheme.outline, CircleShape)
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center
    ) { content() }
}

/** Grande carte chiffrée : libellé, gros chiffre, détail. */
@Composable
private fun BigStat(label: String, value: String, sub: String, icon: ImageVector, modifier: Modifier = Modifier, accent: Color? = null) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
    ) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(label, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f), maxLines = 2)
                Box(Modifier.size(32.dp).clip(CircleShape).background(Brand.BlueSoft), contentAlignment = Alignment.Center) {
                    Icon(icon, null, tint = accent ?: Brand.Blue, modifier = Modifier.size(17.dp))
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 30.sp, color = accent ?: MaterialTheme.colorScheme.onSurface, maxLines = 1)
            Text(sub, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
        }
    }
}

@Composable
private fun ActionBanner(title: String, sub: String, action: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Brand.AmberSoft).clickable(onClick = onClick).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Filled.Warning, null, tint = Brand.Amber, modifier = Modifier.size(26.dp))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Brand.Amber)
            Text(sub, fontSize = 12.sp, color = Brand.Amber)
        }
        Text(action, fontWeight = FontWeight.Bold, color = Brand.Amber)
    }
}

@Composable
private fun InfoLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(label, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
        Text(value, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

private fun Modifier.clickableNoRipple(onClick: () -> Unit): Modifier = this.clickable(onClick = onClick)

private enum class Health { OK, WARN, ERROR }

@Composable
private fun StatusCard(state: UiState) {
    val recent = state.lastSync > 0 && System.currentTimeMillis() - state.lastSync < 10 * 60 * 1000
    val health = when {
        !state.smsPermission -> Health.ERROR
        state.lastError.isNotEmpty() -> Health.WARN
        !state.serviceOn || !recent -> Health.WARN
        else -> Health.OK
    }
    val (fg, bg, title) = when (health) {
        Health.OK -> Triple(Brand.Green, Brand.GreenSoft, "Passerelle active")
        Health.WARN -> Triple(Brand.Amber, Brand.AmberSoft, if (state.lastError.isNotEmpty()) "Problème de connexion" else "En attente de connexion")
        Health.ERROR -> Triple(Brand.Red, Brand.RedSoft, "Action requise")
    }
    val detail = when {
        !state.smsPermission -> "Autorisez la réception des SMS pour confirmer les paiements."
        state.lastError.isNotEmpty() -> state.lastError
        else -> "Les paiements sont confirmés automatiquement."
    }
    val pulse = rememberInfiniteTransition(label = "pulse")
    val a by pulse.animateFloat(0.35f, 1f, infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "a")

    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(bg), contentAlignment = Alignment.Center) {
                Icon(
                    if (health == Health.OK) Icons.Filled.CheckCircle else Icons.Filled.Warning, null,
                    tint = fg, modifier = Modifier.size(30.dp)
                )
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(9.dp).alpha(if (health == Health.OK) a else 1f).clip(CircleShape).background(fg))
                    Spacer(Modifier.width(7.dp))
                    Text(title, fontWeight = FontWeight.Bold, fontSize = 18.sp, color = MaterialTheme.colorScheme.onSurface)
                }
                Spacer(Modifier.height(3.dp))
                Text(detail, color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 13.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
            }
        }
        Spacer(Modifier.height(14.dp))
        HorizontalDivider(color = MaterialTheme.colorScheme.outline)
        Spacer(Modifier.height(12.dp))
        Row {
            InfoCell("Dernier contact", if (state.lastSync > 0) relative(state.lastSync) else "—", Modifier.weight(1f))
            InfoCell("Service", if (state.serviceOn) "En marche" else "Arrêté", Modifier.weight(1f))
        }
    }
}

@Composable
private fun InfoCell(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 12.sp)
        Text(value, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurface)
    }
}

@Composable
private fun StatTile(label: String, value: String, sub: String, modifier: Modifier = Modifier, accent: Color? = null) {
    Card(
        modifier = modifier,
        shape = MaterialTheme.shapes.medium,
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
    ) {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 14.dp)) {
            Text(value, fontWeight = FontWeight.ExtraBold, fontSize = 24.sp, color = accent ?: MaterialTheme.colorScheme.onSurface)
            Text(label, fontWeight = FontWeight.SemiBold, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface, maxLines = 1)
            Text(sub, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
        }
    }
}

@Composable
private fun ChecklistCard(activity: MainActivity, state: UiState, askPermissions: () -> Unit) {
    SectionCard {
        CardTitle("Réglages du téléphone")
        CheckRow(Icons.Filled.Email, "Réception des SMS", "Indispensable pour lire les confirmations", state.smsPermission, "Autoriser", onClick = askPermissions)
        CheckRow(Icons.Filled.Notifications, "Notifications", "Affiche l'état de la passerelle", state.notifPermission, "Autoriser", onClick = askPermissions)
        CheckRow(Icons.Filled.Notifications, "Lecture des notifications (Wave)", "Pour les paiements annoncés par notification", state.notifAccess, "Autoriser") { activity.openNotificationAccess() }
        CheckRow(Icons.Filled.Lock, "Fonctionnement en continu", "Désactive l'économie de batterie pour l'app", state.batteryOk, "Régler") { activity.openBatterySettings() }
        if (state.paired) {
            CheckRow(Icons.Filled.Settings, "Service de la passerelle", "Tourne en arrière-plan en permanence", state.serviceOn, "Démarrer", last = true) { activity.startGateway() }
        }
        Spacer(Modifier.height(6.dp))
        if (!state.notifAccess) {
            Text(
                "Pour Wave : touchez « Autoriser », puis activez « Passerelle Pay » dans « Accès aux notifications ».\n" +
                    "Si l'interrupteur est grisé (« Paramètre restreint ») : Paramètres → Applications → Passerelle Pay → menu ⋮ en haut à droite → « Autoriser les paramètres restreints », puis recommencez.",
                color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 12.sp,
                modifier = Modifier.padding(bottom = 8.dp)
            )
        }
        Notice(
            "Gardez le téléphone branché et connecté à Internet. Sur Tecno, Infinix, itel ou Xiaomi, activez aussi « Démarrage automatique ».",
            MaterialTheme.colorScheme.onSurfaceVariant, MaterialTheme.colorScheme.surfaceVariant, Icons.Filled.Info
        )
    }
}

@Composable
private fun CheckRow(icon: ImageVector, title: String, sub: String, ok: Boolean, action: String, last: Boolean = false, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(38.dp).clip(RoundedCornerShape(11.dp)).background(if (ok) Brand.GreenSoft else Brand.AmberSoft),
            contentAlignment = Alignment.Center
        ) { Icon(if (ok) Icons.Filled.CheckCircle else icon, null, tint = if (ok) Brand.Green else Brand.Amber, modifier = Modifier.size(20.dp)) }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurface)
            Text(sub, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (!ok) {
            OutlinedButton(onClick = onClick, contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp), shape = MaterialTheme.shapes.small) {
                Text(action, fontSize = 13.sp)
            }
        }
    }
}

@Composable
private fun PayoutCard(activity: MainActivity, state: UiState, toast: (String) -> Unit, refresh: () -> Unit) {
    SectionCard {
        CardTitle("Envois d'argent (remboursements)")
        if (state.payoutOps.isEmpty() || !state.payoutsServer) {
            Text(
                "Pas encore disponible : sur le site, renseignez le « Code USSD pour envoyer » dans Opérateurs.",
                color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 13.sp
            )
            return@SectionCard
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Envois automatiques", fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                Text("Ce téléphone envoie l'argent demandé depuis votre espace", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Switch(checked = state.payoutsOn, onCheckedChange = {
                activity.store.payoutsEnabled = it
                activity.store.log(if (it) "💸 Envois d'argent activés" else "Envois d'argent désactivés")
                refresh()
            })
        }
        if (!state.payoutsOn) return@SectionCard
        Spacer(Modifier.height(4.dp))
        CheckRow(Icons.Filled.Phone, "Appels (codes USSD)", "Pour composer le code d'envoi sur la bonne SIM", state.callPermission && state.phoneStatePermission, "Autoriser") { activity.askPayoutPermissions() }
        CheckRow(Icons.Filled.Settings, "Accessibilité", "Pour saisir le code secret dans la fenêtre USSD", state.accessibilityOn, "Activer") { activity.openAccessibility() }
        if (!state.accessibilityOn) {
            Text(
                "Touchez « Activer », ouvrez « Applications installées » (ou « Services téléchargés »), choisissez « Passerelle Pay : envois d'argent » et activez-le. Si c'est grisé : Paramètres → Applications → Passerelle Pay → ⋮ → « Autoriser les paramètres restreints ».",
                fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(bottom = 6.dp)
            )
        }
        state.payoutOps.forEach { op -> PinRow(activity, op, op.code in state.payoutPins, toast, refresh) }
        Spacer(Modifier.height(8.dp))
        Notice(
            "Le code secret est chiffré et reste dans ce téléphone : il n'est jamais envoyé au site. Pour que la fenêtre USSD s'affiche écran éteint, mettez le verrouillage de l'écran sur « Aucun » ou « Glisser ».",
            MaterialTheme.colorScheme.onSurfaceVariant, MaterialTheme.colorScheme.surfaceVariant, Icons.Filled.Lock
        )
    }
}

@Composable
private fun PinRow(activity: MainActivity, op: PayoutOp, hasPin: Boolean, toast: (String) -> Unit, refresh: () -> Unit) {
    var pin by remember { mutableStateOf("") }
    var sim by remember { mutableStateOf(activity.store.simFor(op.code)) }
    val isApp = op.ussd.startsWith("app:")
    var appOn by remember { mutableStateOf(activity.store.appPayoutOn(op.code)) }
    HorizontalDivider(Modifier.padding(vertical = 8.dp))
    if (isApp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(op.name, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                Text(
                    if (appOn) "✅ Envois par l'application ${op.name} activés" else "Le téléphone ouvre l'application ${op.name} et fait l'envoi",
                    fontSize = 12.sp, color = if (appOn) Brand.Green else MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            Switch(checked = appOn, onCheckedChange = {
                appOn = it
                activity.store.setAppPayout(op.code, it)
                activity.store.log(if (it) "💸 Envois ${op.name} activés" else "Envois ${op.name} désactivés")
                refresh()
            })
        }
        if (!appOn) return
        Text(
            "L'application ${op.name} doit être installée et connectée à votre compte sur ce téléphone. Code secret : seulement si ${op.name} le demande à l'ouverture.",
            fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 6.dp)
        )
    }
    if (!isApp) Row(verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(op.name, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            Text(
                (if (hasPin) "✅ Code secret enregistré" else "Aucun code secret") + " · " + op.ussd.replace("{number}", "numéro").replace("{amount}", "montant"),
                fontSize = 12.sp, color = if (hasPin) Brand.Green else MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
        TextButton(onClick = {
            sim = when (sim) { -1 -> 0; 0 -> 1; else -> -1 }
            activity.store.setSim(op.code, sim)
        }) { Text(when (sim) { 0 -> "SIM 1"; 1 -> "SIM 2"; else -> "SIM auto" }, fontSize = 13.sp) }
    }
    Row(verticalAlignment = Alignment.CenterVertically) {
        OutlinedTextField(
            value = pin, onValueChange = { v -> pin = v.filter { it.isDigit() }.take(8) },
            label = { Text(if (hasPin) "Nouveau code secret" else "Code secret ${op.name}") },
            singleLine = true, modifier = Modifier.weight(1f),
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
            shape = MaterialTheme.shapes.medium,
        )
        Spacer(Modifier.width(8.dp))
        if (pin.length >= 4) {
            Button(onClick = {
                PinVault.save(activity, op.code, pin)
                pin = ""
                activity.store.log("🔐 Code secret ${op.name} enregistré")
                toast("Code ${op.name} enregistré")
                refresh()
            }) { Text("OK") }
        } else if (hasPin) {
            TextButton(onClick = {
                PinVault.clear(activity, op.code)
                activity.store.log("Code secret ${op.name} effacé")
                refresh()
            }) { Text("Effacer", color = Brand.Red) }
        }
    }
}

@Composable
private fun ActivityCard(entries: List<LogEntry>, onMore: (() -> Unit)? = null) {
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Activité récente", fontWeight = FontWeight.Bold, fontSize = 18.sp, modifier = Modifier.weight(1f))
            if (onMore != null) TextButton(onClick = onMore) { Text("Tout voir") }
        }
        Spacer(Modifier.height(6.dp))
        if (entries.isEmpty()) {
            Text("Aucun événement pour l'instant. Les SMS de paiement apparaîtront ici.", color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 14.sp)
        }
        entries.forEachIndexed { i, e ->
            val (kind, text) = splitEmoji(e.line)
            val color = when (kind) {
                "✅" -> Brand.Green
                "⚠️", "🔎" -> Brand.Amber
                "❌" -> Brand.Red
                "📩", "🔗", "📤" -> Brand.Blue
                else -> MaterialTheme.colorScheme.onSurfaceVariant
            }
            Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.Top) {
                Box(Modifier.padding(top = 6.dp).size(8.dp).clip(CircleShape).background(color))
                Spacer(Modifier.width(12.dp))
                Text(text, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurface, modifier = Modifier.weight(1f))
                Spacer(Modifier.width(8.dp))
                Text(time(e.timestamp), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (i < entries.size - 1) HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.6f))
        }
    }
}

@Composable
private fun Notice(text: String, fg: Color, bg: Color, icon: ImageVector) {
    Row(
        Modifier.fillMaxWidth().clip(MaterialTheme.shapes.small).background(bg).padding(12.dp),
        verticalAlignment = Alignment.Top
    ) {
        Icon(icon, null, tint = fg, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(10.dp))
        Text(text, color = fg, fontSize = 13.sp)
    }
}

@Composable
private fun PrivacyNote() {
    Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.Lock, null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(8.dp))
        Text(
            "Seuls les SMS des opérateurs et les notifications des applications de paiement (ex. Wave) sont transmis. Le reste ne quitte jamais le téléphone.",
            color = MaterialTheme.colorScheme.onSurfaceVariant, fontSize = 12.sp
        )
    }
}

/* ---------------------------------- Utilitaires ---------------------------------- */

private fun hostOf(url: String): String = try {
    Uri.parse(url).host ?: url
} catch (e: Exception) {
    url
}

private fun time(ts: Long): String {
    val today = SimpleDateFormat("yyyyMMdd", Locale.US).format(Date())
    val day = SimpleDateFormat("yyyyMMdd", Locale.US).format(Date(ts))
    return SimpleDateFormat(if (day == today) "HH:mm" else "dd/MM HH:mm", Locale.FRANCE).format(Date(ts))
}

private fun relative(ts: Long): String {
    val s = (System.currentTimeMillis() - ts) / 1000
    return when {
        s < 60 -> "à l'instant"
        s < 3600 -> "il y a ${s / 60} min"
        s < 86400 -> "il y a ${s / 3600} h"
        else -> SimpleDateFormat("dd/MM HH:mm", Locale.FRANCE).format(Date(ts))
    }
}

/** Sépare l'emoji de tête (type d'événement) du texte. */
private fun splitEmoji(line: String): Pair<String, String> {
    val known = listOf("✅", "⚠️", "🔎", "❌", "📩", "🔗", "📤", "♻️", "ℹ️", "🔄")
    for (k in known) {
        if (line.startsWith(k)) return k to line.removePrefix(k).trim()
    }
    return "" to line
}
