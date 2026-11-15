<?php
declare(strict_types=1);

/** Délais entre les tentatives (secondes). */
const WEBHOOK_BACKOFF = [0, 60, 300, 1800, 7200, 21600, 86400];

function webhook_queue(int $paymentId, string $event): void
{
    $p = q_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    if (!$p) {
        return;
    }
    $merchant = $p['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
    $url = $p['callback_url'] ?: ($merchant['webhook_url'] ?? null);
    if (!$url) {
        return;
    }
    $payload = json_encode([
        'id' => 'evt_' . bin2hex(random_bytes(8)),
        'type' => $event,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'data' => payment_public($p),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $id = db_insert('webhook_deliveries', [
        'payment_id' => $paymentId,
        'url' => $url,
        'event' => $event,
        'payload' => $payload,
        'attempts' => 0,
        'status' => 'pending',
        'next_attempt_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Envoi immédiat ; en cas d'échec, cron.php réessaiera.
    webhook_deliver($id);
}

function webhook_sign(string $payload, string $secret, int $timestamp): string
{
    return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
}

function webhook_deliver(int $deliveryId): bool
{
    $d = q_one('SELECT * FROM webhook_deliveries WHERE id = ?', [$deliveryId]);
    if (!$d || $d['status'] === 'delivered') {
        return true;
    }
    $p = q_one('SELECT merchant_id FROM payments WHERE id = ?', [$d['payment_id']]);
    $merchant = $p && $p['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
    $secret = $merchant['webhook_secret'] ?? (string)cfg('app_secret');
    $ts = time();
    $sig = webhook_sign($d['payload'], $secret, $ts);

    [$code, $body] = http_post_json($d['url'], $d['payload'], [
        'X-Webhook-Event: ' . $d['event'],
        'X-Webhook-Timestamp: ' . $ts,
        'X-Webhook-Signature: sha256=' . $sig,
        'User-Agent: ' . app_name() . '-Webhook/' . APP_VERSION,
    ]);
    $attempts = (int)$d['attempts'] + 1;
    $ok = $code >= 200 && $code < 300;
    $next = null;
    $status = $ok ? 'delivered' : 'pending';
    if (!$ok) {
        if ($attempts >= count(WEBHOOK_BACKOFF)) {
            $status = 'failed';
        } else {
            $next = ts_plus(WEBHOOK_BACKOFF[$attempts]);
        }
    }
    db_update('webhook_deliveries', [
        'attempts' => $attempts,
        'status' => $status,
        'response_code' => $code,
        'response_body' => mb_substr((string)$body, 0, 1000),
        'next_attempt_at' => $next,
        'updated_at' => now(),
    ], 'id = :id', ['id' => $deliveryId]);
    return $ok;
}

function webhooks_retry_due(int $limit = 20): int
{
    $rows = q_all("SELECT id FROM webhook_deliveries WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id ASC LIMIT $limit", [now()]);
    foreach ($rows as $r) {
        webhook_deliver((int)$r['id']);
    }
    return count($rows);
}

/** @return array{0:int,1:string} */
function http_post_json(string $url, string $json, array $headers = [], int $timeout = 10): array
{
    if (!preg_match('#^https?://#i', $url)) {
        return [0, 'URL invalide'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $body = 'Erreur réseau : ' . curl_error($ch);
    }
    curl_close($ch);
    return [$code, (string)$body];
}

/** Tâches périodiques : appelées par cron.php, /cron?key=..., et à chaque appel du téléphone. */
function run_maintenance(): array
{
    return [
        'expired' => payments_expire(),
        'webhooks_retried' => webhooks_retry_due(),
    ];
}
