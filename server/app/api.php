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
    $key = bearer_token();
    $merchant = merchant_from_key($key);
    if (!$merchant && $key) {
        $m = q_one('SELECT status FROM merchants WHERE api_key_hash = ?', [hash('sha256', $key)]);
        if ($m && ($m['status'] ?? '') === 'pending') {
            json_error('account_pending', 'Compte marchand en attente de validation par l\'administrateur.', 403);
        }
    }
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
            } catch (SubscriptionRequired $e) {
                json_error('subscription_required', $e->getMessage(), 402);
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
        if ($method === 'POST' && $action === 'refund') {
            $in = json_input();
            try {
                $o = payout_refund($p, isset($in['amount']) && is_numeric($in['amount']) ? (float)$in['amount'] : null, $merchant, 'api', mb_substr((string)($in['reason'] ?? ''), 0, 120));
                if (!empty($in['callback_url']) && filter_var($in['callback_url'], FILTER_VALIDATE_URL)) {
                    db_update('payouts', ['callback_url' => $in['callback_url']], 'id = :id', ['id' => $o['id']]);
                    $o['callback_url'] = $in['callback_url'];
                }
            } catch (SubscriptionRequired $e) {
                json_error('subscription_required', $e->getMessage(), 402);
            } catch (InvalidArgumentException $e) {
                json_error('invalid_request', $e->getMessage(), 422);
            }
            json_out(payout_public($o), 201);
        }
    }
    if ($resource === 'payouts' || $resource === 'refunds') {
        if ($method === 'POST' && $ref === null && $resource === 'payouts') {
            try {
                $o = payout_create(json_input(), $merchant, 'api');
            } catch (SubscriptionRequired $e) {
                json_error('subscription_required', $e->getMessage(), 402);
            } catch (InvalidArgumentException $e) {
                json_error('invalid_request', $e->getMessage(), 422);
            }
            json_out(payout_public($o), 201);
        }
        if ($method === 'GET' && $ref === null) {
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $params = [$merchant['id']];
            $where = 'merchant_id = ?' . ($resource === 'refunds' ? " AND kind = 'refund'" : '');
            if (!empty($_GET['status'])) {
                $where .= ' AND status = ?';
                $params[] = (string)$_GET['status'];
            }
            $rows = q_all("SELECT * FROM payouts WHERE $where ORDER BY id DESC LIMIT $limit", $params);
            json_out(['object' => 'list', 'data' => array_map('payout_public', $rows)]);
        }
        $o = $ref ? q_one('SELECT * FROM payouts WHERE reference = ? AND merchant_id = ?', [$ref, $merchant['id']]) : null;
        if (!$o) {
            json_error('not_found', 'Envoi introuvable.', 404);
        }
        if ($method === 'GET' && $action === null) {
            json_out(payout_public($o));
        }
        if ($method === 'POST' && $action === 'cancel') {
            if (!payout_cancel($o, 'api')) {
                json_error('invalid_state', 'Seul un envoi pas encore parti (à valider ou en file) peut être annulé.', 409);
            }
            json_out(payout_public(payout_find($ref)));
        }
    }
    if ($resource === 'account' && $method === 'GET') {
        json_out([
            'object' => 'account',
            'name' => $merchant['name'],
            'operators' => array_map(fn($o) => [
                'code' => $o['code'], 'name' => $o['name'], 'merchant_number' => $o['merchant_number'],
            ], array_values(array_filter(operators(true, merchant_scope($merchant)), 'operator_ready'))),
            'currency' => setting('currency'),
            'limits' => ['min_amount' => (float)setting('min_amount'), 'max_amount' => (float)setting('max_amount')],
            'payment_expiry_minutes' => (int)setting('payment_expiry_minutes'),
            'payouts' => [
                'enabled' => payouts_enabled(),
                'operators' => array_values(array_column(array_filter(operators(true, merchant_scope($merchant)), 'operator_can_payout'), 'code')),
                'max_amount' => (float)setting('payout_max', '200000'),
                'daily_limit' => (float)setting('payout_daily_limit', '500000'),
            ],
        ]);
    }
    json_error('not_found', 'Route inconnue.', 404);
}

/* ============================================================
 *  API PUBLIQUE (page de paiement)  —  /api/public/payments/{ref}
 * ============================================================ */

function api_public(string $method, array $parts): never
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    run_maintenance_throttled();
    if (($parts[0] ?? '') === 'payments' && !empty($parts[1])) {
        $p = payment_find((string)$parts[1]);
        if (!$p) {
            json_error('not_found', 'Paiement introuvable.', 404);
        }
        if ($method === 'GET' && !isset($parts[2])) {
            json_out(payment_public($p, false));
        }
        if ($method === 'POST' && ($parts[2] ?? '') === 'payer') {
            $in = json_input();
            try {
                $p = payment_set_payer($p, (string)($in['payer_phone'] ?? ''), isset($in['operator']) ? (string)$in['operator'] : null);
            } catch (InvalidArgumentException $e) {
                json_error('invalid_request', $e->getMessage(), 422);
            }
            json_out(payment_public($p, false));
        }
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

function device_config(?array $device = null): array
{
    $scope = $device && $device['merchant_id'] !== null ? (int)$device['merchant_id'] : null;
    $senders = [];
    foreach (sms_rules() as $r) {
        if (!empty($r['sender'])) {
            $senders[] = $r['sender'];
        }
    }
    foreach (operators(true, $scope) as $o) {
        if (trim((string)$o['sms_sender']) !== '') {
            $senders[] = $o['sms_sender'];
        }
    }
    // Applications dont on lit les notifications (ex. Wave) : motif du nom de paquet -> nom de l'opérateur.
    $apps = [];
    foreach (operators(true, $scope) as $o) {
        if (trim((string)($o['app_package'] ?? '')) !== '') {
            $apps[] = ['package' => $o['app_package'], 'sender' => $o['name']];
        }
    }
    // Envois d'argent : opérateurs que ce téléphone peut utiliser (le code secret reste dans le téléphone).
    $payoutOps = [];
    foreach (operators(true, $scope) as $o) {
        if (operator_can_payout($o)) {
            $payoutOps[] = ['code' => $o['code'], 'name' => $o['name'], 'ussd' => $o['payout_ussd']];
        }
    }
    return [
        'allowed_senders' => array_values(array_unique($senders)),
        'notification_apps' => $apps,
        'payouts' => ['enabled' => payouts_enabled(), 'operators' => $payoutOps, 'queued' => payouts_queued_count($scope)],
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
        json_out(['token' => $token, 'device' => ['id' => (int)$dev['id'], 'name' => $dev['name']], 'config' => device_config($dev)]);
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
        json_out(['ok' => true, 'config' => device_config($device)]);
    }

    if ($action === 'payouts') {
        // POST /api/device/payouts/next   { operators: ["orange","moov"] }  -> prochain envoi à faire
        // POST /api/device/payouts/{ref}/result { status: done|failed|unknown, message, pin_sent }
        $sub = $parts[1] ?? '';
        if ($sub === 'next') {
            db_update('devices', [
                'payout_ops' => mb_substr(implode(',', array_map('strval', (array)($in['operators'] ?? []))), 0, 255),
                'payout_poll_at' => now(),
            ], 'id = :id', ['id' => $device['id']]);
            json_out(['payout' => payout_claim($device, (array)($in['operators'] ?? []))]);
        }
        $o = $sub !== '' ? payout_find($sub) : null;
        if ($o && ($parts[2] ?? '') === 'result') {
            json_out(payout_report($o, $device, (string)($in['status'] ?? 'unknown'), mb_substr((string)($in['message'] ?? ''), 0, 1000), !empty($in['pin_sent'])));
        }
        json_error('not_found', 'Envoi introuvable.', 404);
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
