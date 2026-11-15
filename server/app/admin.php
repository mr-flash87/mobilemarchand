<?php
declare(strict_types=1);

function admin_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    return q_one('SELECT id, email FROM users WHERE id = ?', [$_SESSION['uid']]);
}

function admin_page(string $view, string $title, array $vars = []): void
{
    $vars['title'] = $title;
    $vars['user'] = admin_user();
    $vars['view'] = $view;
    view('admin_layout', $vars);
}

function admin_route(string $method, array $p): void
{
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    $section = $p[0] ?? '';

    if ($section === 'login') {
        if ($method === 'POST') {
            csrf_check();
            $email = trim((string)($_POST['email'] ?? ''));
            $u = q_one('SELECT * FROM users WHERE email = ?', [$email]);
            // petit frein contre les attaques par force brute
            usleep(300000);
            if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                redirect('admin');
            }
            flash('Email ou mot de passe incorrect.', 'err');
            redirect('admin/login');
        }
        view('admin_login');
        return;
    }

    $user = admin_user();
    if (!$user) {
        redirect('admin/login');
    }
    if ($method === 'POST') {
        csrf_check();
    }

    switch ($section) {
        case '':
            admin_dashboard();
            return;
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect('admin/login');
        case 'payments':
            admin_payments($method, $p);
            return;
        case 'sms':
            admin_sms($method, $p);
            return;
        case 'merchants':
            admin_merchants($method, $p);
            return;
        case 'devices':
            admin_devices($method, $p);
            return;
        case 'settings':
            admin_settings($method);
            return;
        case 'simulate':
            admin_simulate($method);
            return;
        case 'operators':
            admin_operators($method);
            return;
        case 'push':
            admin_push($method, $user);
            return;
        case 'password':
            if ($method === 'POST') {
                $new = (string)($_POST['new_password'] ?? '');
                $u = q_one('SELECT * FROM users WHERE id = ?', [$user['id']]);
                if (!password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])) {
                    flash('Mot de passe actuel incorrect.', 'err');
                } elseif (strlen($new) < 8) {
                    flash('Le nouveau mot de passe doit faire au moins 8 caractères.', 'err');
                } else {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                    flash('Mot de passe modifié.');
                }
                redirect('admin/settings');
            }
            redirect('admin/settings');
    }
    http_response_code(404);
    admin_page('admin_404', 'Introuvable');
}

function admin_dashboard(): void
{
    // Jours calculés dans le fuseau horaire de la plateforme, requêtes en UTC.
    $tz = new DateTimeZone((string)setting('timezone', 'Africa/Ouagadougou'));
    $localMidnight = new DateTime('today', $tz);
    $toUtc = fn(DateTime $d) => (clone $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $today = $toUtc($localMidnight);
    $yesterday = $toUtc((clone $localMidnight)->modify('-1 day'));
    $weekStart = (clone $localMidnight)->modify('-6 days');

    $stats = [
        'paid_today' => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND paid_at >= ?", [$today]),
        'count_today' => (int)q_val("SELECT COUNT(*) FROM payments WHERE status = 'paid' AND paid_at >= ?", [$today]),
        'paid_yesterday' => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?", [$yesterday, $today]),
        'pending' => (int)q_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
        'to_review' => (int)q_val("SELECT COUNT(*) FROM sms_messages WHERE status IN ('unmatched','review')"),
        'paid_total' => (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'"),
        'count_total' => (int)q_val("SELECT COUNT(*) FROM payments WHERE status = 'paid'"),
    ];

    // Encaissements des 7 derniers jours (un point par jour, y compris les jours à 0).
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $weekStart)->modify("+$i days");
        $days[$d->format('Y-m-d')] = ['label' => $d->format('d/m'), 'dow' => ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'][(int)$d->format('w')], 'amount' => 0.0, 'count' => 0];
    }
    foreach (q_all("SELECT amount, paid_at FROM payments WHERE status = 'paid' AND paid_at >= ?", [$toUtc($weekStart)]) as $r) {
        $k = (new DateTime($r['paid_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
        if (isset($days[$k])) {
            $days[$k]['amount'] += (float)$r['amount'];
            $days[$k]['count']++;
        }
    }
    $closed = q_one("SELECT SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS ok, COUNT(*) AS n FROM payments WHERE status IN ('paid','expired','cancelled') AND created_at >= ?", [$toUtc($weekStart)]);
    $stats['success_rate'] = $closed && (int)$closed['n'] > 0 ? round(100 * (int)$closed['ok'] / (int)$closed['n']) : null;
    $stats['week_total'] = array_sum(array_column($days, 'amount'));

    $devices = q_all('SELECT * FROM devices WHERE active = 1 ORDER BY id');
    $recent = q_all('SELECT p.*, m.name AS merchant_name FROM payments p LEFT JOIN merchants m ON m.id = p.merchant_id ORDER BY p.id DESC LIMIT 8');
    $sms = q_all('SELECT * FROM sms_messages ORDER BY id DESC LIMIT 6');

    // Liste de mise en route.
    $setup = [
        ['done' => (bool)array_filter(operators(true), 'operator_ready'), 'label' => 'Configurer vos opérateurs et numéros marchands', 'href' => 'admin/operators'],
        ['done' => (bool)q_val('SELECT COUNT(*) FROM merchants'), 'label' => 'Créer un marchand et sa clé API', 'href' => 'admin/merchants'],
        ['done' => (bool)q_val('SELECT COUNT(*) FROM devices WHERE token_hash IS NOT NULL AND active = 1'), 'label' => 'Associer le téléphone passerelle', 'href' => 'admin/devices'],
        ['done' => $stats['count_total'] > 0, 'label' => 'Recevoir un premier paiement (ou le simuler)', 'href' => 'admin/simulate'],
    ];
    admin_page('admin_dashboard', 'Tableau de bord', compact('stats', 'days', 'devices', 'recent', 'sms', 'setup'));
}

function admin_payments(string $method, array $p): void
{
    $ref = $p[1] ?? null;
    if ($ref === 'new' && $method === 'POST') {
        $merchant = !empty($_POST['merchant_id']) ? q_one('SELECT * FROM merchants WHERE id = ?', [(int)$_POST['merchant_id']]) : null;
        try {
            $pay = payment_create([
                'amount' => $_POST['amount'] ?? null,
                'payer_phone' => $_POST['payer_phone'] ?? null,
                'description' => $_POST['description'] ?: 'Paiement créé depuis l\'admin',
            ], $merchant);
            flash('Paiement créé. Envoyez le lien au client.');
            redirect('admin/payments/' . $pay['reference']);
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'err');
            redirect('admin/payments');
        }
    }
    if ($ref) {
        $pay = payment_find($ref);
        if (!$pay) {
            http_response_code(404);
            admin_page('admin_404', 'Introuvable');
            return;
        }
        if ($method === 'POST') {
            $a = $_POST['action'] ?? '';
            if ($a === 'mark_paid') {
                payment_mark_paid($pay, null, 'manual') ? flash('Paiement marqué comme payé, webhook envoyé.') : flash('Impossible : statut actuel ' . $pay['status'], 'err');
            } elseif ($a === 'cancel') {
                payment_set_status($pay, 'cancelled') ? flash('Paiement annulé.') : flash('Seul un paiement en attente peut être annulé.', 'err');
            } elseif ($a === 'resend') {
                $ev = $pay['status'] === 'paid' ? 'payment.succeeded' : 'payment.' . $pay['status'];
                webhook_queue((int)$pay['id'], $ev);
                flash('Webhook renvoyé (voir l\'historique ci-dessous).');
            }
            redirect('admin/payments/' . $ref);
        }
        $merchant = $pay['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$pay['merchant_id']]) : null;
        $sms = $pay['sms_id'] ? q_one('SELECT * FROM sms_messages WHERE id = ?', [$pay['sms_id']]) : null;
        $hooks = q_all('SELECT * FROM webhook_deliveries WHERE payment_id = ? ORDER BY id DESC', [$pay['id']]);
        admin_page('admin_payment', 'Paiement ' . $pay['reference'], compact('pay', 'merchant', 'sms', 'hooks'));
        return;
    }
    $status = (string)($_GET['status'] ?? '');
    $search = trim((string)($_GET['q'] ?? ''));
    $where = '1=1';
    $params = [];
    if ($status !== '') {
        $where .= ' AND p.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where .= ' AND (p.reference LIKE ? OR p.payer_phone LIKE ? OR p.external_ref LIKE ? OR p.operator_txn_id LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }
    $rows = q_all("SELECT p.*, m.name AS merchant_name FROM payments p LEFT JOIN merchants m ON m.id = p.merchant_id WHERE $where ORDER BY p.id DESC LIMIT 200", $params);
    $merchants = q_all('SELECT id, name FROM merchants WHERE active = 1 ORDER BY name');
    admin_page('admin_payments', 'Paiements', compact('rows', 'status', 'search', 'merchants'));
}

function admin_sms(string $method, array $p): void
{
    if ($method === 'POST' && !empty($p[1])) {
        $sms = q_one('SELECT * FROM sms_messages WHERE id = ?', [(int)$p[1]]);
        $a = $_POST['action'] ?? '';
        if ($sms && $a === 'match') {
            $pay = payment_find(trim((string)($_POST['reference'] ?? '')));
            if (!$pay) {
                flash('Référence de paiement introuvable.', 'err');
            } elseif (!in_array($pay['status'], ['pending', 'expired'], true)) {
                flash('Ce paiement est déjà ' . $pay['status'] . '.', 'err');
            } else {
                payment_mark_paid($pay, $sms, 'manual');
                if ($sms['balance'] !== null && $sms['device_id']) {
                    device_set_balance((int)$sms['device_id'], $sms['operator'], (float)$sms['balance']);
                }
                flash('SMS rapproché du paiement ' . $pay['reference'] . '.');
            }
        } elseif ($sms && $a === 'approve' && $sms['status'] === 'review') {
            // L'admin confirme que l'argent est bien arrivé : on resynchronise le solde puis on rapproche.
            if ($sms['balance'] !== null && $sms['device_id']) {
                device_set_balance((int)$sms['device_id'], $sms['operator'], (float)$sms['balance']);
            }
            db_update('sms_messages', ['status' => 'unmatched', 'note' => 'Vérifié par l\'admin'], 'id = :id', ['id' => $sms['id']]);
            $sms = q_one('SELECT * FROM sms_messages WHERE id = ?', [$sms['id']]);
            $pay = $sms['payment_id'] ? q_one('SELECT * FROM payments WHERE id = ?', [$sms['payment_id']]) : payment_match_for((float)$sms['amount'], $sms['phone']);
            if ($pay && payment_mark_paid($pay, $sms, 'manual')) {
                flash('SMS validé et paiement ' . $pay['reference'] . ' confirmé.');
            } else {
                flash('SMS validé. Aucun paiement en attente correspondant : rapprochez-le manuellement si besoin.');
            }
        } elseif ($sms && $a === 'ignore') {
            db_update('sms_messages', ['status' => 'ignored', 'note' => 'Ignoré par l\'admin'], 'id = :id', ['id' => $sms['id']]);
            flash('SMS ignoré.');
        }
        redirect('admin/sms' . (!empty($_POST['back']) ? '?status=' . urlencode((string)$_POST['back']) : ''));
    }
    $status = (string)($_GET['status'] ?? '');
    $rows = $status !== ''
        ? q_all('SELECT s.*, p.reference AS payment_ref FROM sms_messages s LEFT JOIN payments p ON p.id = s.payment_id WHERE s.status = ? ORDER BY s.id DESC LIMIT 200', [$status])
        : q_all('SELECT s.*, p.reference AS payment_ref FROM sms_messages s LEFT JOIN payments p ON p.id = s.payment_id ORDER BY s.id DESC LIMIT 200');
    admin_page('admin_sms', 'SMS reçus', compact('rows', 'status'));
}

function admin_merchants(string $method, array $p): void
{
    if ($method === 'POST') {
        $a = $_POST['action'] ?? '';
        if ($a === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                flash('Nom obligatoire.', 'err');
                redirect('admin/merchants');
            }
            [$key, $prefix, $hash] = merchant_generate_key();
            db_insert('merchants', [
                'name' => mb_substr($name, 0, 190),
                'email' => trim((string)($_POST['email'] ?? '')) ?: null,
                'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
                'api_key_prefix' => $prefix,
                'api_key_hash' => $hash,
                'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')) ?: null,
                'webhook_secret' => 'fpwh_' . random_token(24),
                'active' => 1,
                'created_at' => now(),
            ]);
            start_session();
            $_SESSION['new_key'] = $key;
            flash('Marchand créé. Copiez la clé API maintenant : elle ne sera plus affichée.');
            redirect('admin/merchants');
        }
        $m = q_one('SELECT * FROM merchants WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if ($m) {
            if ($a === 'update') {
                $url = trim((string)($_POST['webhook_url'] ?? ''));
                if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                    flash('URL de webhook invalide.', 'err');
                } else {
                    db_update('merchants', [
                        'name' => mb_substr(trim((string)$_POST['name']) ?: $m['name'], 0, 190),
                        'email' => trim((string)($_POST['email'] ?? '')) ?: null,
                        'phone' => trim((string)($_POST['phone'] ?? '')) ?: null,
                        'webhook_url' => $url ?: null,
                    ], 'id = :id', ['id' => $m['id']]);
                    flash('Marchand mis à jour.');
                }
            } elseif ($a === 'rotate') {
                [$key, $prefix, $hash] = merchant_generate_key();
                db_update('merchants', ['api_key_prefix' => $prefix, 'api_key_hash' => $hash], 'id = :id', ['id' => $m['id']]);
                start_session();
                $_SESSION['new_key'] = $key;
                flash('Nouvelle clé générée. L\'ancienne ne fonctionne plus.');
            } elseif ($a === 'toggle') {
                db_update('merchants', ['active' => $m['active'] ? 0 : 1], 'id = :id', ['id' => $m['id']]);
                flash($m['active'] ? 'Marchand désactivé.' : 'Marchand activé.');
            } elseif ($a === 'test') {
                if (!$m['webhook_url']) {
                    flash('Renseignez d\'abord une URL de webhook.', 'err');
                } else {
                    $payload = json_encode(['id' => 'evt_test', 'type' => 'test', 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => ['message' => 'Webhook de test']]);
                    $ts = time();
                    [$code, $body] = http_post_json($m['webhook_url'], $payload, [
                        'X-Webhook-Event: test',
                        'X-Webhook-Timestamp: ' . $ts,
                        'X-Webhook-Signature: sha256=' . webhook_sign($payload, $m['webhook_secret'], $ts),
                    ]);
                    flash("Réponse du webhook : HTTP $code " . mb_substr($body, 0, 150), $code >= 200 && $code < 300 ? 'ok' : 'err');
                }
            }
        }
        redirect('admin/merchants');
    }
    start_session();
    $newKey = $_SESSION['new_key'] ?? null;
    unset($_SESSION['new_key']);
    $rows = q_all("SELECT m.*, (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.merchant_id = m.id AND p.status = 'paid') AS total_paid,
        (SELECT COUNT(*) FROM payments p WHERE p.merchant_id = m.id AND p.status = 'paid') AS count_paid FROM merchants m ORDER BY m.id");
    admin_page('admin_merchants', 'Marchands & clés API', compact('rows', 'newKey'));
}

function admin_devices(string $method, array $p): void
{
    if ($method === 'POST') {
        $a = $_POST['action'] ?? '';
        if ($a === 'create') {
            $code = (string)random_int(100000, 999999);
            db_insert('devices', [
                'name' => mb_substr(trim((string)($_POST['name'] ?? '')) ?: 'Téléphone', 0, 190),
                'sim_number' => trim((string)($_POST['sim_number'] ?? '')) ?: null,
                'pair_code' => $code,
                'pair_expires_at' => ts_plus(1800),
                'active' => 1,
                'created_at' => now(),
            ]);
            $newId = (int)db()->lastInsertId();
            foreach ((array)($_POST['balances'] ?? []) as $opCode => $val) {
                if (trim((string)$val) !== '' && operator_by_code((string)$opCode)) {
                    device_set_balance($newId, (string)$opCode, parse_amount((string)$val));
                }
            }
            flash("Téléphone ajouté. Code d'association : $code (valable 30 min).");
            redirect('admin/devices');
        }
        if ($a === 'create_ios') {
            $token = 'dev_' . random_token(32);
            db_insert('devices', [
                'name' => mb_substr(trim((string)($_POST['name'] ?? '')) ?: 'iPhone', 0, 190),
                'sim_number' => trim((string)($_POST['sim_number'] ?? '')) ?: null,
                'token_hash' => hash('sha256', $token),
                'kind' => 'ios',
                'active' => 1,
                'created_at' => now(),
            ]);
            start_session();
            $_SESSION['ios_token'] = $token;
            flash('iPhone ajouté. Suivez le guide ci-dessous pour créer l\'automatisation dans Raccourcis.');
            redirect('admin/devices#ios-guide');
        }
        $d = q_one('SELECT * FROM devices WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if ($d) {
            if ($a === 'ios_token' && ($d['kind'] ?? '') === 'ios') {
                $token = 'dev_' . random_token(32);
                db_update('devices', ['token_hash' => hash('sha256', $token)], 'id = :id', ['id' => $d['id']]);
                start_session();
                $_SESSION['ios_token'] = $token;
                flash('Nouvelle clé générée : remplacez-la dans votre automatisation Raccourcis (l\'ancienne ne marche plus).');
                redirect('admin/devices#ios-guide');
            } elseif ($a === 'code') {
                $code = (string)random_int(100000, 999999);
                db_update('devices', ['pair_code' => $code, 'pair_expires_at' => ts_plus(1800)], 'id = :id', ['id' => $d['id']]);
                flash("Nouveau code d'association : $code (valable 30 min). L'ancien téléphone reste actif jusqu'à la nouvelle association.");
            } elseif ($a === 'balance') {
                foreach ((array)($_POST['balances'] ?? []) as $opCode => $val) {
                    if (operator_by_code((string)$opCode)) {
                        $val = trim((string)$val);
                        device_set_balance((int)$d['id'], (string)$opCode, $val === '' ? null : parse_amount($val));
                    }
                }
                flash('Solde de référence mis à jour.');
            } elseif ($a === 'revoke') {
                db_update('devices', ['active' => 0, 'token_hash' => null, 'pair_code' => null], 'id = :id', ['id' => $d['id']]);
                flash('Téléphone révoqué : il ne peut plus envoyer de SMS.');
            }
        }
        redirect('admin/devices');
    }
    $rows = q_all('SELECT d.*, (SELECT COUNT(*) FROM sms_messages s WHERE s.device_id = d.id) AS sms_count FROM devices d ORDER BY d.active DESC, d.id');
    start_session();
    $iosToken = $_SESSION['ios_token'] ?? null;
    unset($_SESSION['ios_token']);
    admin_page('admin_devices', 'Téléphones passerelle', compact('rows', 'iosToken'));
}

function admin_settings(string $method): void
{
    $testResult = null;
    $testInput = ['sender' => 'OrangeMoney', 'body' => ''];
    if ($method === 'POST') {
        $a = $_POST['action'] ?? 'save';
        if ($a === 'save') {
            $fields = ['app_name', 'timezone', 'currency', 
                'payment_expiry_minutes', 'min_amount', 'max_amount', 'late_match_minutes', 'support_whatsapp'];
            foreach ($fields as $f) {
                if (isset($_POST[$f])) {
                    setting_set($f, trim((string)$_POST[$f]));
                }
            }
            setting_set('match_amount_only', !empty($_POST['match_amount_only']) ? '1' : '0');
            setting_set('balance_check', !empty($_POST['balance_check']) ? '1' : '0');
            if (!in_array((string)setting('timezone'), DateTimeZone::listIdentifiers(), true)) {
                setting_set('timezone', 'Africa/Ouagadougou');
            }
            flash('Réglages enregistrés.');
            redirect('admin/settings');
        }
        if ($a === 'rules' || $a === 'test') {
            $json = (string)($_POST['sms_rules'] ?? '');
            $rules = json_decode($json, true);
            $error = null;
            if (!is_array($rules)) {
                $error = 'JSON invalide : ' . json_last_error_msg();
            } else {
                foreach ($rules as $i => $r) {
                    foreach (['sender', 'require', 'exclude', 'amount', 'phone', 'txn', 'balance'] as $f) {
                        if (isset($r[$f]) && !rx_valid((string)$r[$f])) {
                            $error = "Règle #" . ($i + 1) . " : regex « $f » invalide.";
                        }
                    }
                }
            }
            if ($error) {
                flash($error, 'err');
            } elseif ($a === 'rules') {
                setting_set('sms_rules', json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                flash('Règles SMS enregistrées.');
                redirect('admin/settings#rules');
            }
            if ($a === 'test' && !$error) {
                $testInput = ['sender' => (string)($_POST['test_sender'] ?? ''), 'body' => (string)($_POST['test_body'] ?? '')];
                $testResult = parse_sms($testInput['sender'], $testInput['body'], $rules) ?? false;
            }
            $rulesJson = $json;
        }
        if ($a === 'reset_rules') {
            setting_set('sms_rules', setting_defaults()['sms_rules']);
            flash('Règles SMS réinitialisées.');
            redirect('admin/settings#rules');
        }
        if ($a === 'cron_key') {
            setting_set('cron_key', bin2hex(random_bytes(16)));
            flash('Nouvelle clé cron générée.');
            redirect('admin/settings#cron');
        }
    }
    $rulesJson ??= (string)setting('sms_rules');
    admin_page('admin_settings', 'Réglages', compact('rulesJson', 'testResult', 'testInput'));
}

function admin_simulate(string $method): void
{
    $result = null;
    if ($method === 'POST') {
        $device = !empty($_POST['device_id']) ? q_one('SELECT * FROM devices WHERE id = ?', [(int)$_POST['device_id']]) : null;
        $result = sms_ingest($device, (string)($_POST['sender'] ?? ''), (string)($_POST['body'] ?? ''), (int)(microtime(true) * 1000), ($_POST['source'] ?? '') === 'notification' ? 'notification' : 'sms');
    }
    $devices = q_all('SELECT id, name FROM devices WHERE active = 1');
    $pending = q_all("SELECT * FROM payments WHERE status = 'pending' ORDER BY id DESC LIMIT 10");
    admin_page('admin_simulate', 'Simuler un SMS', compact('result', 'devices', 'pending'));
}

/** Abonnement aux notifications (appelé en JavaScript depuis l'admin). */
function admin_push(string $method, array $user): never
{
    if ($method !== 'POST') {
        json_out(['public_key' => vapid_public_b64u(), 'supported' => webpush_supported()]);
    }
    $a = (string)($_POST['action'] ?? '');
    $endpoint = (string)($_POST['endpoint'] ?? '');
    if ($a === 'subscribe') {
        if (!preg_match('#^https://#', $endpoint) || empty($_POST['p256dh']) || empty($_POST['auth'])) {
            json_error('invalid_request', 'Abonnement invalide.', 422);
        }
        $hash = hash('sha256', $endpoint);
        q('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
        db_insert('push_subscriptions', [
            'user_id' => $user['id'],
            'endpoint' => mb_substr($endpoint, 0, 500),
            'endpoint_hash' => $hash,
            'p256dh' => mb_substr((string)$_POST['p256dh'], 0, 255),
            'auth' => mb_substr((string)$_POST['auth'], 0, 100),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => now(),
        ]);
        json_out(['ok' => true]);
    }
    if ($a === 'unsubscribe') {
        q('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
        json_out(['ok' => true]);
    }
    if ($a === 'test') {
        $n = push_notify('🔔 Notifications activées', 'Vous serez prévenu à chaque paiement reçu.', 'admin', 'test');
        json_out(['ok' => $n > 0, 'sent' => $n]);
    }
    json_error('invalid_request', 'Action inconnue.', 422);
}

function admin_operators(string $method): void
{
    if ($method === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        $fields = function (): array {
            $color = trim((string)($_POST['color'] ?? ''));
            return [
                'name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0b5fff',
                'merchant_number' => mb_substr(trim((string)($_POST['merchant_number'] ?? '')), 0, 40),
                'ussd_codes' => trim((string)($_POST['ussd_codes'] ?? '')),
                'pay_link' => mb_substr(trim((string)($_POST['pay_link'] ?? '')), 0, 500),
                'sms_sender' => mb_substr(trim((string)($_POST['sms_sender'] ?? '')), 0, 255),
                'app_package' => mb_substr(trim((string)($_POST['app_package'] ?? '')), 0, 255),
                'instructions' => trim((string)($_POST['instructions'] ?? '')),
                'active' => !empty($_POST['active']) ? 1 : 0,
                'ussd_primary' => max(0, (int)($_POST['ussd_primary'] ?? 0)),
                'ussd_show_alt' => !empty($_POST['ussd_show_alt']) ? 1 : 0,
            ];
        };
        $check = function (array $f): ?string {
            if ($f['name'] === '') {
                return 'Le nom est obligatoire.';
            }
            if ($f['sms_sender'] !== '' && !rx_valid($f['sms_sender'])) {
                return 'Expéditeur SMS : expression invalide.';
            }
            if ($f['app_package'] !== '' && !rx_valid($f['app_package'])) {
                return 'Application (notifications) : expression invalide.';
            }
            if ($f['pay_link'] !== '' && !preg_match('#^https?://#', $f['pay_link'])) {
                return 'Le lien de paiement doit commencer par https://';
            }
            return null;
        };
        if ($a === 'create') {
            $f = $fields();
            $code = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string)($_POST['code'] ?? '')) ?: $f['name']));
            $code = trim($code, '-');
            if ($err = $check($f)) {
                flash($err, 'err');
            } elseif ($code === '' || operator_by_code($code)) {
                flash('Ce code d\'opérateur existe déjà ou est vide.', 'err');
            } else {
                db_insert('operators', $f + ['code' => $code, 'sort_order' => count(operators()) + 1, 'created_at' => now()]);
                flash('Opérateur « ' . $f['name'] . ' » ajouté.');
            }
        } else {
            $op = operator_by_id((int)($_POST['id'] ?? 0));
            if (!$op) {
                flash('Opérateur introuvable.', 'err');
            } elseif ($a === 'update') {
                $f = $fields();
                if ($err = $check($f)) {
                    flash($err, 'err');
                } else {
                    db_update('operators', $f, 'id = :id', ['id' => $op['id']]);
                    flash('Opérateur « ' . $f['name'] . ' » enregistré.');
                }
            } elseif ($a === 'up' || $a === 'down') {
                $list = operators();
                $i = array_search($op['id'], array_column($list, 'id'));
                $j = $a === 'up' ? $i - 1 : $i + 1;
                if (isset($list[$j])) {
                    [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
                    foreach ($list as $k => $o) {
                        db_update('operators', ['sort_order' => $k + 1], 'id = :id', ['id' => $o['id']]);
                    }
                }
            } elseif ($a === 'delete') {
                if ((int)q_val('SELECT COUNT(*) FROM payments WHERE operator_id = ?', [$op['id']]) > 0) {
                    db_update('operators', ['active' => 0], 'id = :id', ['id' => $op['id']]);
                    flash('Des paiements utilisent cet opérateur : il a été désactivé au lieu d\'être supprimé.');
                } else {
                    q('DELETE FROM operators WHERE id = ?', [$op['id']]);
                    flash('Opérateur supprimé.');
                }
            }
        }
        redirect('admin/operators');
    }
    $rows = operators();
    $stats = [];
    foreach (q_all("SELECT paid_operator, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM payments WHERE status = 'paid' GROUP BY paid_operator") as $r) {
        $stats[(string)$r['paid_operator']] = $r;
    }
    admin_page('admin_operators', 'Opérateurs', compact('rows', 'stats'));
}
