<?php
declare(strict_types=1);

/* ============================================================
 *  API MARCHAND  —  /api/v1/...
 *  Authentification : Authorization: Bearer fp_live_xxx
 * ============================================================ */

function merchant_from_key(?string $key): ?array
{
    if (!$key) {
        return null;
    }
    $m = q_one('SELECT * FROM merchants WHERE api_key_hash = ? AND active = 1', [hash('sha256', $key)]);
    return $m ?: null;
}

function merchant_generate_key(): array
{
    $key = 'fp_live_' . random_token(24);
    return [$key, substr($key, 0, 14), hash('sha256', $key)];
}

function api_merchant(string $method, array $parts): never
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    $merchant = merchant_from_key(bearer_token());
    if (!$merchant) {
        json_error('unauthorized', 'Clé API manquante ou invalide. En-tête attendu : Authorization: Bearer fp_live_...', 401);
    }
    run_maintenance_throttled();

    $resource = $parts[0] ?? '';
    $ref = $parts[1] ?? null;
    $action = $parts[2] ?? null;

    if ($resource === 'payments') {
        if ($method === 'POST' && $ref === null) {
            try {
                $p = payment_create(json_input(), $merchant);
            } catch (InvalidArgumentException $e) {
                json_error('invalid_request', $e->getMessage(), 422);
            }
            json_out(payment_public($p), 201);
        }
        if ($method === 'GET' && $ref === null) {
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $params = [$merchant['id']];
            $where = 'merchant_id = ?';
            if (!empty($_GET['status'])) {
                $where .= ' AND status = ?';
                $params[] = (string)$_GET['status'];
            }
            if (!empty($_GET['reference'])) {
                $where .= ' AND external_ref = ?';
                $params[] = (string)$_GET['reference'];
            }
            $rows = q_all("SELECT * FROM payments WHERE $where ORDER BY id DESC LIMIT $limit", $params);
            json_out(['object' => 'list', 'data' => array_map('payment_public', $rows)]);
        }
        $p = $ref ? q_one('SELECT * FROM payments WHERE reference = ? AND merchant_id = ?', [$ref, $merchant['id']]) : null;
        if (!$p) {
            json_error('not_found', 'Paiement introuvable.', 404);
        }
        if ($method === 'GET' && $action === null) {
            json_out(payment_public($p));
        }
        if ($method === 'POST' && $action === 'cancel') {
            if (!payment_set_status($p, 'cancelled')) {
                json_error('invalid_state', 'Seul un paiement en attente peut être annulé.', 409);
            }
            json_out(payment_public(payment_find($ref)));
        }
    }
    if ($resource === 'account' && $method === 'GET') {
        json_out([
            'object' => 'account',
            'name' => $merchant['name'],
            'operators' => array_map(fn($o) => [
                'code' => $o['code'], 'name' => $o['name'], 'merchant_number' => $o['merchant_number'],
            ], array_values(array_filter(operators(true), 'operator_ready'))),
            'currency' => setting('currency'),
            'limits' => ['min_amount' => (float)setting('min_amount'), 'max_amount' => (float)setting('max_amount')],
            'payment_expiry_minutes' => (int)setting('payment_expiry_minutes'),
        ]);
    }
    json_error('not_found', 'Route inconnue.', 404);
}

/* ============================================================
 *  API PUBLIQUE (page de paiement)  —  /api/public/payments/{ref}
 * ============================================================ */

function api_public(string $method, array $parts): never
{
    run_maintenance_throttled();
    if ($method === 'GET' && ($parts[0] ?? '') === 'payments' && !empty($parts[1])) {
        $p = payment_find((string)$parts[1]);
        if (!$p) {
            json_error('not_found', 'Paiement introuvable.', 404);
        }
        json_out(payment_public($p, false));
    }
    json_error('not_found', 'Route inconnue.', 404);
}

/* ============================================================
 *  API TÉLÉPHONE (passerelle Android)  —  /api/device/...
 * ============================================================ */

function device_from_token(?string $token): ?array
{
    if (!$token) {
        return null;
    }
    return q_one('SELECT * FROM devices WHERE token_hash = ? AND active = 1', [hash('sha256', $token)]);
}

function device_config(): array
{
    $senders = [];
    foreach (sms_rules() as $r) {
        if (!empty($r['sender'])) {
            $senders[] = $r['sender'];
        }
    }
    foreach (operators(true) as $o) {
        if (trim((string)$o['sms_sender']) !== '') {
            $senders[] = $o['sms_sender'];
        }
    }
    // Applications dont on lit les notifications (ex. Wave) : motif du nom de paquet -> nom de l'opérateur.
    $apps = [];
    foreach (operators(true) as $o) {
        if (trim((string)($o['app_package'] ?? '')) !== '') {
            $apps[] = ['package' => $o['app_package'], 'sender' => $o['name']];
        }
    }
    return [
        'allowed_senders' => array_values(array_unique($senders)),
        'notification_apps' => $apps,
        'heartbeat_seconds' => 120,
        'server_time' => time(),
        'app_name' => app_name(),
    ];
}

function api_device(string $method, array $parts): never
{
    $action = $parts[0] ?? '';
    if ($method !== 'POST') {
        json_error('method_not_allowed', 'POST attendu.', 405);
    }
    $in = json_input();

    if ($action === 'pair') {
        $code = preg_replace('/\D+/', '', (string)($in['code'] ?? ''));
        $dev = $code !== '' ? q_one('SELECT * FROM devices WHERE pair_code = ? AND active = 1', [$code]) : null;
        if (!$dev || !$dev['pair_expires_at'] || $dev['pair_expires_at'] < now()) {
            json_error('invalid_code', 'Code d\'association invalide ou expiré. Générez-en un nouveau dans l\'admin.', 403);
        }
        $token = 'dev_' . random_token(32);
        db_update('devices', [
            'token_hash' => hash('sha256', $token),
            'pair_code' => null,
            'pair_expires_at' => null,
            'last_seen_at' => now(),
            'app_version' => mb_substr((string)($in['app_version'] ?? ''), 0, 20),
        ], 'id = :id', ['id' => $dev['id']]);
        json_out(['token' => $token, 'device' => ['id' => (int)$dev['id'], 'name' => $dev['name']], 'config' => device_config()]);
    }

    $device = device_from_token(bearer_token());
    if (!$device) {
        json_error('unauthorized', 'Téléphone non associé ou révoqué.', 401);
    }
    $seen = ['last_seen_at' => now()];
    if (isset($in['battery'])) {
        $seen['battery'] = max(0, min(100, (int)$in['battery']));
    }
    if (isset($in['charging'])) {
        $seen['charging'] = $in['charging'] ? 1 : 0;
    }
    if (!empty($in['app_version'])) {
        $seen['app_version'] = mb_substr((string)$in['app_version'], 0, 20);
    }
    db_update('devices', $seen, 'id = :id', ['id' => $device['id']]);

    if ($action === 'heartbeat') {
        run_maintenance_throttled();
        json_out(['ok' => true, 'config' => device_config()]);
    }

    if ($action === 'sms') {
        $messages = $in['messages'] ?? null;
        if ($messages === null && isset($in['body'])) {
            // Format simple (Raccourcis iPhone) : { "sender": "...", "body": "..." }
            $messages = [[
                'sender' => trim((string)($in['sender'] ?? '')) ?: (operator_by_code((string)($in['operator'] ?? ''))['name'] ?? 'Mobile Money'),
                'body' => (string)$in['body'],
                'source' => 'sms',
            ]];
        }
        if (!is_array($messages) || count($messages) === 0) {
            json_error('invalid_request', 'messages[] attendu.', 422);
        }
        $results = [];
        foreach (array_slice($messages, 0, 50) as $m) {
            if (!is_array($m) || !isset($m['sender'], $m['body'])) {
                $results[] = ['client_id' => $m['client_id'] ?? null, 'status' => 'invalid'];
                continue;
            }
            // recharger le téléphone pour avoir le dernier solde connu
            $device = q_one('SELECT * FROM devices WHERE id = ?', [$device['id']]);
            $source = ($m['source'] ?? 'sms') === 'notification' ? 'notification' : 'sms';
            $r = sms_ingest($device, (string)$m['sender'], (string)$m['body'], isset($m['timestamp']) ? (int)$m['timestamp'] : null, $source);
            $r['client_id'] = $m['client_id'] ?? null;
            $results[] = $r;
        }
        run_maintenance_throttled();
        json_out(['ok' => true, 'results' => $results]);
    }

    json_error('not_found', 'Route inconnue.', 404);
}

function run_maintenance_throttled(): void
{
    $flag = APP_ROOT . '/data/.last_maintenance';
    $last = is_file($flag) ? (int)@file_get_contents($flag) : 0;
    if (time() - $last < 60) {
        return;
    }
    if (!is_dir(dirname($flag))) {
        log_line('init');
    }
    @file_put_contents($flag, (string)time());
    try {
        run_maintenance();
    } catch (Throwable $e) {
        log_line('maintenance error: ' . $e->getMessage());
    }
}
