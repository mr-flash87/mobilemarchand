<?php
declare(strict_types=1);

/**
 * Notifications "Web Push" (RFC 8030 / 8291 / 8292) sans bibliothèque externe :
 * chiffrement aes128gcm + authentification VAPID (ES256), uniquement avec OpenSSL.
 */

const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    $s = strtr($s, '-_', '+/');
    return (string)base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function webpush_supported(): bool
{
    return function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/** Clé publique brute (65 octets, 0x04||X||Y) d'une clé EC. */
function ec_raw_public($key): string
{
    $d = openssl_pkey_get_details($key);
    $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return "\x04" . $x . $y;
}

function ec_new_key()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

function ec_public_from_raw(string $raw)
{
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(P256_SPKI_PREFIX) . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** Clés VAPID du serveur : générées une seule fois puis conservées dans les réglages. */
function vapid_keys(): array
{
    $pem = (string)setting('vapid_private_pem', '');
    $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
    if (!$key) {
        $key = ec_new_key();
        openssl_pkey_export($key, $pem);
        setting_set('vapid_private_pem', $pem);
    }
    return ['private' => $key, 'public_raw' => ec_raw_public($key)];
}

function vapid_public_b64u(): string
{
    return b64u_enc(vapid_keys()['public_raw']);
}

/** Signature ES256 : OpenSSL renvoie du DER, JWT exige R||S (64 octets). */
function es256_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = (string)cfg('app_url', '');
    if (!preg_match('#^https://#', $sub)) {
        $sub = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $jf = JSON_UNESCAPED_SLASHES;
    $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], $jf));
    openssl_sign("$h.$c", $der, $keys['private'], OPENSSL_ALGO_SHA256);
    $jwt = "$h.$c." . b64u_enc(es256_der_to_raw($der));
    return 'vapid t=' . $jwt . ', k=' . b64u_enc($keys['public_raw']);
}

/** Chiffre le message pour un abonné (RFC 8291, aes128gcm). */
function webpush_encrypt(string $payload, string $uaPublicRaw, string $authSecret): string
{
    $as = ec_new_key();
    $asPublic = ec_raw_public($as);
    $shared = openssl_pkey_derive(ec_public_from_raw($uaPublicRaw), $as, 32);
    if ($shared === false) {
        throw new RuntimeException('ECDH impossible');
    }
    $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublicRaw . $asPublic . "\x01", $prkKey, true);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** Envoie une notification à un abonnement. Retourne le code HTTP (0 = erreur réseau). */
function webpush_send(array $sub, array $message): int
{
    $body = webpush_encrypt(
        json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        b64u_dec($sub['p256dh']),
        b64u_dec($sub['auth'])
    );
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . vapid_header($sub['endpoint']),
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * Notifie les appareils abonnés d'un périmètre : null = administrateur de la plateforme,
 * id = marchand autonome. $path est relatif à l'espace (ex. "payments/PAY-…").
 */
function push_notify(string $title, string $body, string $path = '', string $tag = '', ?int $scope = null): int
{
    tg_notify($title, $body, $path, $tag, $scope); // mêmes alertes sur Telegram (privé ou groupe)
    if (!webpush_supported()) {
        return 0;
    }
    $sent = 0;
    try {
        $params = [];
        $subs = q_all('SELECT * FROM push_subscriptions WHERE ' . scope_sql('merchant_id', $scope, $params), $params);
        $base = $scope === null ? 'admin' : 'espace';
        foreach ($subs as $s) {
            $code = webpush_send($s, [
                'title' => $title,
                'body' => $body,
                'url' => abs_url($base . ($path !== '' ? '/' . ltrim($path, '/') : '')),
                'tag' => $tag ?: 'fp-' . bin2hex(random_bytes(4)),
            ]);
            if ($code === 404 || $code === 410) {
                q('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]); // abonnement expiré
            } elseif ($code >= 200 && $code < 300) {
                $sent++;
            } else {
                log_line("push #{$s['id']} HTTP $code");
            }
        }
    } catch (Throwable $e) {
        log_line('push error: ' . $e->getMessage());
    }
    return $sent;
}
