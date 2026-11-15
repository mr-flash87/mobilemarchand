<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ts_plus(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

function iso(?string $dt): ?string
{
    return $dt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC')) : null;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    return rtrim(dirname($script), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    $configured = rtrim((string)cfg('app_url', ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'AZ'), '=');
}

function new_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'PAY-' . $s;
}

/** Clé de comparaison d'un numéro : les 8 derniers chiffres (numéros locaux BF/CI/SN...). */
function phone_key(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '' || strlen($d) < 8) {
        return null;
    }
    return substr($d, -8);
}

function parse_amount(?string $raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $raw);
    // "5.000" ou "5,000" (séparateur de milliers) vs "5000,50" / "5000.50" (décimales)
    if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) {
        $s = preg_replace('/[.,]/', '', $s);
    } else {
        $s = str_replace(',', '.', $s);
        if (substr_count($s, '.') > 1) {
            $s = preg_replace('/\.(?=.*\.)/', '', $s);
        }
    }
    if (!is_numeric($s)) {
        return null;
    }
    return round((float)$s, 2);
}

function fmt_amount($amount, string $currency = 'F'): string
{
    $n = (float)$amount;
    $dec = fmod($n, 1.0) == 0.0 ? 0 : 2;
    return number_format($n, $dec, ',', ' ') . ' ' . $currency;
}

function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $tz = new DateTimeZone((string)setting('timezone', 'Africa/Ouagadougou'));
    $d = new DateTime($dt, new DateTimeZone('UTC'));
    $d->setTimezone($tz);
    return $d->format('d/m/Y H:i:s');
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $code, string $message, int $http = 400, array $extra = []): never
{
    json_out(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $http);
}

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('invalid_json', 'Le corps de la requête doit être du JSON valide.');
    }
    return $data;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) {
        return $m[1];
    }
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? '';
    return $alt !== '' ? trim($alt) : null;
}

function app_name(): string
{
    return (string)setting('app_name', cfg('app_name', 'FlashPay'));
}

function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_DIR . '/views/' . $name . '.php';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('smspay_admin');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => base_path() === '' ? '/' : base_path() . '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Session expirée. Rechargez la page.');
    }
}

function flash(?string $msg = null, string $type = 'ok')
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function log_line(string $msg): void
{
    $dir = APP_ROOT . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    @file_put_contents($dir . '/app.log', '[' . now() . '] ' . $msg . "\n", FILE_APPEND);
}

function status_label(string $s): string
{
    return [
        'pending' => 'En attente', 'paid' => 'Payé', 'expired' => 'Expiré', 'cancelled' => 'Annulé',
        'matched' => 'Rapproché', 'unmatched' => 'Non rapproché', 'review' => 'À vérifier', 'duplicate' => 'Doublon',
        'ignored' => 'Ignoré', 'info' => 'Info solde', 'delivered' => 'Livré', 'failed' => 'Échec',
        'online' => 'En ligne', 'offline' => 'Hors ligne',
    ][$s] ?? $s;
}

function badge(string $s): string
{
    return '<span class="badge b-' . h($s) . '">' . h(status_label($s)) . '</span>';
}

function device_online(array $d): bool
{
    return $d['last_seen_at'] && strtotime($d['last_seen_at'] . ' UTC') > time() - 600;
}

/** Icônes SVG (trait 2px, style "outline"). */
function icon(string $name, int $size = 18): string
{
    $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
        'sms' => '<path d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9l-5 4v-4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M8 9h8M8 12.5h5"/>',
        'store' => '<path d="M3 9.5 4.5 4h15L21 9.5M3 9.5h18M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12.5V20h14v-7.5"/><path d="M10 20v-4h4v4"/>',
        'phone' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18h2"/>',
        'flask' => '<path d="M9 3h6M10 3v6l-5.5 9.5A1.7 1.7 0 0 0 6 21h12a1.7 1.7 0 0 0 1.5-2.5L14 9V3"/><path d="M7.5 15h9"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'book' => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H20v15H5.5A1.5 1.5 0 0 0 4 19.5z"/><path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H20v-3"/><path d="M8 7h8M8 10.5h6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><circle cx="16" cy="14.5" r="1.2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'battery' => '<rect x="2.5" y="7" width="17" height="10" rx="2"/><path d="M22 11v2"/>',
    ][$name] ?? '';
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
