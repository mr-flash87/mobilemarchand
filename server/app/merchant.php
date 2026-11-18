<?php
declare(strict_types=1);

/* ============================================================
 *  ESPACE MARCHAND  —  /espace/...
 *  Comptes autonomes : chaque marchand a ses opérateurs, ses numéros
 *  et ses téléphones. Il ne voit que ses propres données.
 * ============================================================ */

function merchant_session(): ?array
{
    start_session();
    if (empty($_SESSION['mid'])) {
        return null;
    }
    $m = q_one("SELECT * FROM merchants WHERE id = ? AND kind = 'tenant'", [(int)$_SESSION['mid']]);
    if (!$m || $m['status'] === 'suspended') {
        unset($_SESSION['mid']);
        return null;
    }
    return $m;
}

function merchant_route(string $method, array $p): void
{
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    ctx_set('merchant');
    $section = $p[0] ?? '';

    if ($section === 'inscription') {
        merchant_signup($method);
        return;
    }
    if ($section === 'connexion') {
        merchant_login($method);
        return;
    }

    $m = merchant_session();
    if (!$m) {
        redirect('espace/connexion');
    }
    ctx_set('merchant', $m);
    if ($method === 'POST') {
        csrf_check();
    }

    switch ($section) {
        case '':
            admin_dashboard();
            return;
        case 'logout':
            unset($_SESSION['mid']);
            session_regenerate_id(true);
            redirect('espace/connexion');
        case 'payments':
            admin_payments($method, $p);
            return;
        case 'sms':
            admin_sms($method, $p);
            return;
        case 'operators':
            admin_operators($method);
            return;
        case 'devices':
            admin_devices($method, $p);
            return;
        case 'simulate':
            admin_simulate($method);
            return;
        case 'push':
            admin_push($method, ['id' => null]);
            return;
        case 'telegram':
            admin_telegram($method);
            return;
        case 'payouts':
            admin_payouts($method);
            return;
        case 'api':
            merchant_api($method, $m);
            return;
        case 'subscription':
            merchant_subscription($method, $m);
            return;
        case 'settings':
            merchant_settings($method, $m);
            return;
    }
    http_response_code(404);
    admin_page('admin_404', 'Introuvable');
}

function merchant_signup(string $method): void
{
    $old = ['name' => '', 'email' => '', 'phone' => '', 'website' => ''];
    $error = null;
    if ($method === 'POST') {
        csrf_check();
        $old = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'website' => trim((string)($_POST['website'] ?? '')),
        ];
        $pass = (string)($_POST['password'] ?? '');
        usleep(300000);
        if (!empty($_POST['company_url'])) { // champ piège anti-robots
            $error = 'Inscription refusée.';
        } elseif (mb_strlen($old['name']) < 2) {
            $error = 'Indiquez le nom de votre entreprise ou de votre site.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } elseif (phone_key($old['phone']) === null) {
            $error = 'Numéro de téléphone invalide.';
        } elseif (strlen($pass) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } elseif (empty($_POST['terms'])) {
            $error = 'Vous devez accepter les conditions d\'utilisation.';
        } elseif (q_val('SELECT COUNT(*) FROM merchants WHERE LOWER(email) = ?', [$old['email']])) {
            $error = 'Un compte existe déjà avec cet email. Connectez-vous.';
        }
        if (!$error) {
            // Clé API inutilisable tant que le compte n'est pas validé (le marchand la génère ensuite).
            $placeholder = hash('sha256', 'pending-' . random_token(24));
            $id = db_insert('merchants', [
                'name' => mb_substr($old['name'], 0, 190),
                'email' => $old['email'],
                'phone' => mb_substr($old['phone'], 0, 40),
                'api_key_prefix' => '—',
                'api_key_hash' => $placeholder,
                'webhook_url' => null,
                'webhook_secret' => 'fpwh_' . random_token(24),
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'kind' => 'tenant',
                'status' => 'pending',
                'active' => 0,
                'created_at' => now(),
            ]);
            operators_seed($id); // ses propres opérateurs, à compléter avec ses numéros
            start_session();
            session_regenerate_id(true);
            $_SESSION['mid'] = $id;
            push_notify('🆕 Nouveau compte marchand', $old['name'] . ' (' . $old['email'] . ') attend votre validation.', 'merchants', 'merchant-' . $id, null);
            log_line("merchant signup #$id {$old['email']}");
            flash('Bienvenue ! Votre compte est créé. Configurez vos opérateurs et votre téléphone pendant que nous validons votre compte.');
            redirect('espace');
        }
    }
    view('merchant_auth', ['mode' => 'signup', 'old' => $old, 'error' => $error]);
}

function merchant_login(string $method): void
{
    if (merchant_session()) {
        redirect('espace');
    }
    $error = null;
    $email = '';
    if ($method === 'POST') {
        csrf_check();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        usleep(300000);
        $m = q_one("SELECT * FROM merchants WHERE LOWER(email) = ? AND kind = 'tenant'", [$email]);
        if ($m && $m['password_hash'] && password_verify((string)($_POST['password'] ?? ''), $m['password_hash'])) {
            if ($m['status'] === 'suspended') {
                $error = 'Ce compte est suspendu. Contactez le support.';
            } else {
                start_session();
                session_regenerate_id(true);
                $_SESSION['mid'] = (int)$m['id'];
                redirect('espace');
            }
        } elseif (q_val('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?', [$email])) {
            // L'administrateur s'est trompé de porte : on l'oriente vers son espace.
            $error = 'admin';
        } else {
            $error = 'Email ou mot de passe incorrect.';
        }
    }
    view('merchant_auth', ['mode' => 'login', 'old' => ['email' => $email], 'error' => $error]);
}

function merchant_api(string $method, array $m): void
{
    if ($method === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'rotate') {
            if ($m['status'] !== 'active') {
                flash('Votre compte doit d\'abord être validé par l\'administrateur.', 'err');
            } else {
                [$key, $prefix, $hash] = merchant_generate_key();
                db_update('merchants', ['api_key_prefix' => $prefix, 'api_key_hash' => $hash, 'active' => 1], 'id = :id', ['id' => $m['id']]);
                $_SESSION['new_key'] = $key;
                flash('Nouvelle clé API générée. Copiez-la maintenant : elle ne sera plus affichée.');
            }
        } elseif ($a === 'webhook') {
            $url = trim((string)($_POST['webhook_url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                flash('URL de webhook invalide.', 'err');
            } else {
                db_update('merchants', ['webhook_url' => $url ?: null], 'id = :id', ['id' => $m['id']]);
                flash('URL de webhook enregistrée.');
            }
        } elseif ($a === 'secret') {
            db_update('merchants', ['webhook_secret' => 'fpwh_' . random_token(24)], 'id = :id', ['id' => $m['id']]);
            flash('Nouveau secret de webhook généré : mettez-le à jour sur votre site.');
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
                flash("Réponse de votre webhook : HTTP $code " . mb_substr($body, 0, 150), $code >= 200 && $code < 300 ? 'ok' : 'err');
            }
        }
        redirect('espace/api');
    }
    $newKey = $_SESSION['new_key'] ?? null;
    unset($_SESSION['new_key']);
    $m = q_one('SELECT * FROM merchants WHERE id = ?', [$m['id']]);
    admin_page('merchant_api', 'Clés API & webhook', ['m' => $m, 'newKey' => $newKey]);
}

function merchant_settings(string $method, array $m): void
{
    if ($method === 'POST') {
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if (mb_strlen($name) < 2 || phone_key($phone) === null) {
                flash('Nom ou téléphone invalide.', 'err');
            } else {
                db_update('merchants', ['name' => mb_substr($name, 0, 190), 'phone' => mb_substr($phone, 0, 40)], 'id = :id', ['id' => $m['id']]);
                flash('Profil enregistré.');
            }
        } elseif ($a === 'password') {
            $new = (string)($_POST['new_password'] ?? '');
            if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$m['password_hash'])) {
                flash('Mot de passe actuel incorrect.', 'err');
            } elseif (strlen($new) < 8) {
                flash('Le nouveau mot de passe doit faire au moins 8 caractères.', 'err');
            } else {
                db_update('merchants', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', ['id' => $m['id']]);
                flash('Mot de passe modifié.');
            }
        }
        redirect('espace/settings');
    }
    admin_page('merchant_settings', 'Mon compte', ['m' => $m]);
}

function merchant_subscription(string $method, array $m): void
{
    if ($method === 'POST') {
        $phone = trim((string)($_POST['payer_phone'] ?? ''));
        if (phone_key($phone) === null) {
            flash('Indiquez le numéro avec lequel vous allez payer.', 'err');
            redirect('espace/subscription');
        }
        try {
            $pay = subscription_create_payment($m, $phone);
        } catch (InvalidArgumentException $e) {
            flash('Paiement impossible pour le moment : ' . $e->getMessage(), 'err');
            redirect('espace/subscription');
        }
        redirect('pay/' . $pay['reference']);
    }
    $m = q_one('SELECT * FROM merchants WHERE id = ?', [$m['id']]);
    $history = q_all(
        "SELECT * FROM payments WHERE merchant_id IS NULL AND external_ref = ? ORDER BY id DESC LIMIT 20",
        ['ABO-' . $m['id']]
    );
    admin_page('merchant_subscription', 'Abonnement', ['m' => $m, 'history' => $history]);
}
