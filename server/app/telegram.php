<?php
declare(strict_types=1);

/* ============================================================
 *  BOT TELEGRAM
 *  Le tableau de bord dans Telegram, en privé ou dans un groupe :
 *  notifications de paiements, statistiques, paiements, SMS, téléphones,
 *  opérateurs, marchands/abonnement, création de liens de paiement.
 *
 *  Un seul bot pour toute la plateforme (jeton réglé par l'admin).
 *  Chaque discussion est reliée à un espace : l'admin (merchant_id NULL)
 *  ou un marchand inscrit, grâce à un code à usage unique généré dans l'espace.
 * ============================================================ */

function tg_token(): string
{
    return trim((string)setting('telegram_bot_token', ''));
}

/** Échappement HTML pour Telegram (les apostrophes restent lisibles). */
function tg_h($s): string
{
    return htmlspecialchars((string)$s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tg_enabled(): bool
{
    return tg_token() !== '';
}

function tg_username(): string
{
    return (string)setting('telegram_bot_username', '');
}

/** Appel de l'API Bot de Telegram. */
function tg_api(string $method, array $params = [], int $timeout = 8, ?string $token = null): array
{
    $token ??= tg_token();
    if ($token === '') {
        return ['ok' => false, 'description' => 'Bot non configuré'];
    }
    [$code, $body] = http_post_json(
        rtrim((string)cfg('telegram_api', 'https://api.telegram.org'), '/') . '/bot' . $token . '/' . $method,
        (string)json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        [],
        $timeout
    );
    $r = json_decode($body, true);
    if (!is_array($r)) {
        $r = ['ok' => false, 'error_code' => $code, 'description' => $code ? "HTTP $code" : mb_substr($body, 0, 150)];
    }
    if (empty($r['ok']) && !str_contains((string)($r['description'] ?? ''), 'message is not modified')) {
        log_line("telegram $method: " . ($r['description'] ?? $code));
    }
    return $r;
}

function tg_commands(): array
{
    return [
        ['command' => 'menu', 'description' => 'Tableau de bord'],
        ['command' => 'stats', 'description' => 'Encaissements du jour et de la semaine'],
        ['command' => 'paiements', 'description' => 'Derniers paiements'],
        ['command' => 'attente', 'description' => 'Paiements en attente'],
        ['command' => 'creer', 'description' => 'Créer un lien de paiement : /creer 5000'],
        ['command' => 'paiement', 'description' => 'Détail d\'un paiement : /paiement PAY-…'],
        ['command' => 'sms', 'description' => 'SMS à vérifier ou non rapprochés'],
        ['command' => 'telephones', 'description' => 'État des téléphones passerelle'],
        ['command' => 'operateurs', 'description' => 'Opérateurs et numéros marchands'],
        ['command' => 'abonnement', 'description' => 'Mon abonnement (marchands)'],
        ['command' => 'marchands', 'description' => 'Comptes marchands (admin)'],
        ['command' => 'envoyer', 'description' => 'Envoyer de l\'argent : /envoyer 5000 70112233 orange'],
        ['command' => 'envois', 'description' => 'Envois et remboursements'],
        ['command' => 'rembourser', 'description' => 'Rembourser un paiement : /rembourser PAY-…'],
        ['command' => 'notifs', 'description' => 'Activer / couper les notifications ici'],
        ['command' => 'delier', 'description' => 'Délier cette discussion'],
        ['command' => 'aide', 'description' => 'Aide'],
    ];
}

/** Enregistre le jeton, branche le webhook et la liste des commandes. */
function tg_setup(string $token): array
{
    $token = trim($token);
    $old = tg_token();
    if ($token === '') {
        if ($old !== '') {
            tg_api('deleteWebhook', [], 8, $old);
        }
        setting_set('telegram_bot_token', '');
        setting_set('telegram_bot_username', '');
        return [true, 'Bot Telegram désactivé.'];
    }
    if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
        return [false, 'Jeton invalide : il ressemble à 123456789:AAH… (donné par @BotFather).'];
    }
    $me = tg_api('getMe', [], 8, $token);
    if (empty($me['ok'])) {
        return [false, 'Telegram refuse ce jeton : ' . ($me['description'] ?? 'erreur inconnue')];
    }
    $secret = bin2hex(random_bytes(20));
    $wh = tg_api('setWebhook', [
        'url' => abs_url('telegram/webhook'),
        'secret_token' => $secret,
        'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        'drop_pending_updates' => true,
    ], 10, $token);
    if (empty($wh['ok'])) {
        return [false, 'Impossible de brancher le webhook : ' . ($wh['description'] ?? 'erreur inconnue') . ' (le site doit être en https).'];
    }
    setting_set('telegram_bot_token', $token);
    setting_set('telegram_bot_username', (string)($me['result']['username'] ?? ''));
    setting_set('telegram_secret', $secret);
    tg_api('setMyCommands', ['commands' => tg_commands()]);
    tg_api('setMyDescription', ['description' => app_name() . ' : suivez vos paiements Mobile Money, recevez une alerte à chaque paiement et gérez votre espace depuis Telegram.']);
    return [true, 'Bot @' . tg_username() . ' connecté.'];
}

/** Code à usage unique pour relier une discussion Telegram à l'espace courant. */
function tg_new_link_code(?int $merchantId, bool $canAct): string
{
    q('DELETE FROM telegram_links WHERE expires_at < ?', [now()]);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'FP';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    db_insert('telegram_links', [
        'code' => $code,
        'merchant_id' => $merchantId,
        'can_act' => $canAct ? 1 : 0,
        'expires_at' => ts_plus(1800),
        'created_at' => now(),
    ]);
    return $code;
}

/* ------------------------------------------------------------------
 *  Notifications (appelées par push_notify : mêmes événements que le téléphone)
 * ------------------------------------------------------------------ */

function tg_notify(string $title, string $body, string $path, string $tag, ?int $scope): int
{
    if (!tg_enabled()) {
        return 0;
    }
    $sent = 0;
    try {
        $params = [];
        $chats = q_all('SELECT * FROM telegram_chats WHERE notify = 1 AND ' . scope_sql('merchant_id', $scope, $params), $params);
        if (!$chats) {
            return 0;
        }
        $buttons = [];
        if (str_starts_with($tag, 'out-')) {
            $o = payout_find(substr($tag, 4));
            if ($o && $o['status'] === 'approval') {
                $buttons[] = ['✅ Valider l\'envoi', 'oa:' . $o['reference']];
                $buttons[] = ['🚫 Refuser', 'or:' . $o['reference']];
            } elseif ($o) {
                $buttons[] = ['🔎 Détails', 'od:' . $o['reference']];
            }
        } elseif (str_starts_with($tag, 'pay-')) {
            $buttons[] = ['🔎 Détails', 'pd:' . substr($tag, 4)];
        } elseif (str_starts_with($tag, 'sms-')) {
            $buttons[] = ['🔎 Voir les SMS', 'sm'];
        } elseif (str_starts_with($tag, 'merchant-')) {
            $buttons[] = ['🏪 Marchands', 'mc'];
        }
        $link = abs_url(($scope === null ? 'admin' : 'espace') . ($path !== '' ? '/' . ltrim($path, '/') : ''));
        if (str_starts_with($link, 'https://')) {
            $buttons[] = ['🌐 Ouvrir', $link];
        }
        $text = '<b>' . tg_h($title) . '</b>' . ($body !== '' ? "\n" . tg_h($body) : '');
        foreach ($chats as $c) {
            $r = tg_api('sendMessage', [
                'chat_id' => $c['chat_id'],
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => tg_kb([$buttons]),
            ], 6);
            if (!empty($r['ok'])) {
                $sent++;
            } elseif (in_array((int)($r['error_code'] ?? 0), [400, 403], true)
                && preg_match('/blocked|kicked|not found|deactivated|not a member/i', (string)($r['description'] ?? ''))) {
                q('DELETE FROM telegram_chats WHERE id = ?', [$c['id']]); // bot retiré du groupe ou bloqué
            }
        }
    } catch (Throwable $e) {
        log_line('telegram notify: ' . $e->getMessage());
    }
    return $sent;
}

/* ------------------------------------------------------------------
 *  Réception des messages (webhook)
 * ------------------------------------------------------------------ */

function tg_webhook(): never
{
    $secret = (string)setting('telegram_secret', '');
    $got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($secret === '' || !hash_equals($secret, $got)) {
        http_response_code(403);
        exit('forbidden');
    }
    $update = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($update)) {
        try {
            tg_handle($update);
        } catch (Throwable $e) {
            log_line('telegram: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $cid = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
            if ($cid !== null) {
                tg_send($cid, '❌ Erreur : ' . tg_h(mb_substr($e->getMessage(), 0, 300)) . "\nRéessayez, ou envoyez une capture au support.");
            }
        }
    }
    json_out(['ok' => true]); // toujours 200 : sinon Telegram renvoie le message en boucle
}

function tg_handle(array $u): void
{
    if (isset($u['my_chat_member'])) {
        $st = $u['my_chat_member']['new_chat_member']['status'] ?? '';
        if (in_array($st, ['left', 'kicked'], true)) {
            q('DELETE FROM telegram_chats WHERE chat_id = ?', [(string)$u['my_chat_member']['chat']['id']]);
        }
        return;
    }
    if (isset($u['callback_query'])) {
        tg_on_callback($u['callback_query']);
        return;
    }
    $msg = $u['message'] ?? null;
    if (!$msg) {
        return;
    }
    $chat = $msg['chat'];
    if (!empty($msg['migrate_to_chat_id'])) { // groupe devenu « supergroupe » : nouvel identifiant
        q('UPDATE telegram_chats SET chat_id = ? WHERE chat_id = ?', [(string)$msg['migrate_to_chat_id'], (string)$chat['id']]);
        return;
    }
    $text = trim((string)($msg['text'] ?? ''));
    if ($text === '') {
        return;
    }
    $from = $msg['from'] ?? [];
    $private = ($chat['type'] ?? '') === 'private';

    // Réponse à la question « Montant ? » du bouton « Créer un paiement ».
    $replyTo = $msg['reply_to_message']['text'] ?? '';
    if ($text[0] !== '/' && str_starts_with($replyTo, '➕ Nouveau paiement')) {
        $text = '/creer ' . $text;
    } elseif ($private && preg_match('/^\d[\d\s.]*(\s.*)?$/s', $text)) {
        $text = '/creer ' . $text; // en privé, un simple « 5000 » crée un paiement
    }

    // Code de liaison envoyé tel quel (ex. « FP2K6HGN6X », « /start FP… », « lier fp… »).
    if (preg_match('~^/?(?:(?:start|lier)(?:@\w+)?\s*)?(FP[A-Z0-9\s-]{8,14})$~i', $text, $cm)
        && strlen($code = strtoupper((string)preg_replace('/[\s-]+/', '', $cm[1]))) === 10) {
        tg_do_link($chat, $from, $code);
        return;
    }

    if (!preg_match('~^/([A-Za-z_]+)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$~s', $text, $m)) {
        if ($private) {
            tg_send($chat['id'], 'Touchez /menu pour afficher le tableau de bord, ou /aide.');
        }
        return;
    }
    if (!empty($m[2]) && strcasecmp($m[2], tg_username()) !== 0) {
        return; // commande destinée à un autre bot du groupe
    }
    $cmd = strtolower($m[1]);
    $args = trim($m[3] ?? '');

    if (($cmd === 'start' || $cmd === 'lier') && $args !== '') {
        tg_do_link($chat, $from, strtoupper(preg_replace('/[\s-]+/', '', $args)));
        return;
    }
    if (in_array($cmd, ['start', 'aide', 'help'], true) && !tg_load_chat($chat['id'])) {
        tg_send($chat['id'], tg_help_unlinked());
        return;
    }

    $row = tg_load_chat($chat['id']);
    if (!$row) {
        tg_send($chat['id'], tg_help_unlinked());
        return;
    }
    $actor = ['chat' => $chat, 'from' => $from, 'row' => $row];

    switch ($cmd) {
        case 'start':
        case 'menu':
            tg_reply_screen($chat['id'], tg_screen_menu());
            return;
        case 'aide':
        case 'help':
            tg_send($chat['id'], tg_help());
            return;
        case 'stats':
            tg_reply_screen($chat['id'], tg_screen_stats());
            return;
        case 'paiements':
            tg_reply_screen($chat['id'], tg_screen_payments(0));
            return;
        case 'attente':
            tg_reply_screen($chat['id'], tg_screen_pending());
            return;
        case 'paiement':
            tg_reply_screen($chat['id'], $args === '' ? ['Indiquez la référence : <code>/paiement PAY-XXXXXXXXXX</code>', []] : tg_screen_payment(strtoupper($args)));
            return;
        case 'sms':
            tg_reply_screen($chat['id'], tg_screen_sms());
            return;
        case 'telephones':
            tg_reply_screen($chat['id'], tg_screen_devices());
            return;
        case 'operateurs':
            tg_reply_screen($chat['id'], tg_screen_operators());
            return;
        case 'abonnement':
            tg_reply_screen($chat['id'], tg_screen_subscription());
            return;
        case 'marchands':
            tg_reply_screen($chat['id'], tg_screen_merchants());
            return;
        case 'creer':
            if ($args === '') {
                tg_ask_amount($chat['id'], $from, $private);
                return;
            }
            if ($err = tg_deny($actor)) {
                tg_send($chat['id'], $err);
                return;
            }
            tg_reply_screen($chat['id'], tg_create_payment($args));
            return;
        case 'rembourser':
            if ($err = tg_deny($actor)) {
                tg_send($chat['id'], $err);
                return;
            }
            $a = preg_split('/\s+/', $args);
            $p = tg_find_payment(strtoupper($a[0] ?? ''));
            if (!$p) {
                tg_send($chat['id'], 'Utilisation : <code>/rembourser PAY-XXXXXXXXXX</code> (tout) ou <code>/rembourser PAY-XXXXXXXXXX 2000</code>');
                return;
            }
            try {
                $o = payout_refund($p, isset($a[1]) ? (float)preg_replace('/\D/', '', $a[1]) : null, $p['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$p['merchant_id']]) : null, 'telegram');
                tg_reply_screen($chat['id'], tg_screen_payout($o['reference']));
            } catch (InvalidArgumentException $e) {
                tg_send($chat['id'], '❌ ' . tg_h(preg_replace('/^\w+: /', '', $e->getMessage())));
            }
            return;
        case 'rapprocher':
            if ($err = tg_deny($actor)) {
                tg_send($chat['id'], $err);
                return;
            }
            $a = preg_split('/\s+/', $args);
            $sms = count($a) >= 2 ? tg_find_sms((int)ltrim($a[0], '#')) : null;
            if (!$sms) {
                tg_send($chat['id'], 'Utilisation : <code>/rapprocher 12 PAY-XXXXXXXXXX</code> (numéro du SMS, puis référence du paiement).');
                return;
            }
            [$ok, $txt] = sms_action($sms, 'match', strtoupper($a[1]));
            tg_send($chat['id'], ($ok ? '✅ ' : '❌ ') . tg_h($txt));
            return;
        case 'envois':
            tg_reply_screen($chat['id'], tg_screen_payouts());
            return;
        case 'envoyer':
            if ($err = tg_deny($actor)) {
                tg_send($chat['id'], $err);
                return;
            }
            tg_reply_screen($chat['id'], tg_create_payout($args, $from));
            return;
        case 'notifs':
            if (!$private && !tg_is_chat_admin($chat['id'], (int)($from['id'] ?? 0))) {
                tg_send($chat['id'], '⛔ Seul un administrateur du groupe peut changer ce réglage.');
                return;
            }
            $on = (int)$row['notify'] ? 0 : 1;
            db_update('telegram_chats', ['notify' => $on], 'id = :id', ['id' => $row['id']]);
            tg_send($chat['id'], $on ? '🔔 Notifications activées : chaque paiement reçu sera annoncé ici.' : '🔕 Notifications coupées pour cette discussion (les commandes restent disponibles).');
            return;
        case 'delier':
            if (!$private && !tg_is_chat_admin($chat['id'], (int)($from['id'] ?? 0))) {
                tg_send($chat['id'], '⛔ Seul un administrateur du groupe peut délier ce groupe.');
                return;
            }
            q('DELETE FROM telegram_chats WHERE id = ?', [$row['id']]);
            tg_send($chat['id'], '👋 Discussion déliée : elle ne reçoit plus rien. Générez un nouveau code dans votre espace pour la relier à nouveau.');
            return;
        case 'id':
            tg_send($chat['id'], 'Identifiant de cette discussion : <code>' . tg_h((string)$chat['id']) . '</code>');
            return;
    }
    tg_send($chat['id'], 'Commande inconnue. Touchez /menu ou /aide.');
}

function tg_on_callback(array $cb): void
{
    $data = (string)($cb['data'] ?? '');
    $msg = $cb['message'] ?? null;
    if (!$msg) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        return;
    }
    $chat = $msg['chat'];
    $row = tg_load_chat($chat['id']);
    if (!$row) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Discussion non reliée.', 'show_alert' => true]);
        return;
    }
    $actor = ['chat' => $chat, 'from' => $cb['from'] ?? [], 'row' => $row];
    [$op, $arg] = array_pad(explode(':', $data, 2), 2, '');
    $toast = null;

    // Actions (modifient des données) : autorisation vérifiée d'abord.
    if (in_array($op, ['pK', 'pC', 'sa', 'si', 'ma', 'ot', 'nw', 'oa', 'or', 'oS', 'oF', 'rF'], true) && ($err = tg_deny($actor))) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => strip_tags($err), 'show_alert' => true]);
        return;
    }

    switch ($op) {
        case 'm': $screen = tg_screen_menu(); break;
        case 'st': $screen = tg_screen_stats(); break;
        case 'pl': $screen = tg_screen_payments(max(0, (int)$arg)); break;
        case 'pe': $screen = tg_screen_pending(); break;
        case 'pd': $screen = tg_screen_payment($arg); break;
        case 'pk':
            $screen = tg_screen_confirm($arg, 'Marquer ce paiement comme <b>payé</b> ? Le marchand est prévenu (webhook). Faites-le seulement si l\'argent est bien arrivé.', 'pK', '✅ Oui, marquer payé');
            break;
        case 'pc':
            $screen = tg_screen_confirm($arg, '<b>Annuler</b> ce paiement en attente ?', 'pC', '❌ Oui, annuler');
            break;
        case 'pK':
        case 'pC':
            $p = tg_find_payment($arg);
            if (!$p) {
                $toast = 'Paiement introuvable.';
            } elseif ($op === 'pK') {
                $toast = payment_mark_paid($p, null, 'manual') ? '✅ Paiement marqué payé.' : 'Impossible : statut ' . status_label($p['status']) . '.';
            } else {
                $toast = payment_set_status($p, 'cancelled') ? 'Paiement annulé.' : 'Seul un paiement en attente peut être annulé.';
            }
            $screen = tg_screen_payment($arg);
            break;
        case 'sm': $screen = tg_screen_sms(); break;
        case 'ol': $screen = tg_screen_payouts(); break;
        case 'od': $screen = tg_screen_payout($arg); break;
        case 'oa':
        case 'or':
        case 'oS':
        case 'oF':
            $o = tg_find_payout($arg);
            $by = 'telegram:' . trim(($cb['from']['first_name'] ?? '') . ' ' . ($cb['from']['username'] ?? ''));
            $done = $o && match ($op) {
                'oa' => payout_approve($o, $by),
                'or' => payout_cancel($o, $by),
                'oS' => payout_resolve($o, 'success', $by),
                'oF' => payout_resolve($o, 'failed', $by),
            };
            $toast = !$o ? 'Envoi introuvable.' : ($done ? ['oa' => '✅ Validé : le téléphone va envoyer l\'argent.', 'or' => 'Envoi refusé.', 'oS' => 'Noté : argent parti.', 'oF' => 'Noté : rien n\'est parti.'][$op] : 'Déjà traité (' . payout_status_label($o['status']) . ').');
            $screen = tg_screen_payout($arg);
            break;
        case 'rf':
            $p = tg_find_payment($arg);
            $screen = $p && $p['status'] === 'paid'
                ? ['↩️ <b>Rembourser</b> ' . tg_h(fmt_amount($p['paid_amount'] ?: $p['amount'], 'F')) . ' au client ' . tg_h($p['payer_phone'] ?: '') . " ?\nLe téléphone passerelle renverra l'argent par le même opérateur.\n<i>Pour un montant partiel : /rembourser " . tg_h($p['reference']) . ' 2000</i>',
                    [[['↩️ Oui, rembourser tout', 'rF:' . $p['reference']], ['↩️ Non', 'pd:' . $p['reference']]]]]
                : tg_screen_payment($arg);
            break;
        case 'rF':
            $p = tg_find_payment($arg);
            try {
                $o = $p ? payout_refund($p, null, $p['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$p['merchant_id']]) : null, 'telegram') : null;
                $screen = $o ? tg_screen_payout($o['reference']) : tg_screen_payment($arg);
                $toast = $o ? 'Remboursement créé.' : 'Paiement introuvable.';
            } catch (InvalidArgumentException $e) {
                $toast = preg_replace('/^\w+: /', '', $e->getMessage());
                $screen = tg_screen_payment($arg);
            }
            break;
        case 'sa':
        case 'si':
            $sms = tg_find_sms((int)$arg);
            $toast = $sms ? sms_action($sms, $op === 'sa' ? 'approve' : 'ignore')[1] : 'SMS introuvable.';
            $screen = tg_screen_sms();
            break;
        case 'dv': $screen = tg_screen_devices(); break;
        case 'op': $screen = tg_screen_operators(); break;
        case 'ot':
            $o = operator_by_id((int)$arg);
            if ($o && operator_in_scope($o, current_scope())) {
                db_update('operators', ['active' => (int)$o['active'] ? 0 : 1], 'id = :id', ['id' => $o['id']]);
                operators_reset_cache();
                $toast = $o['name'] . ((int)$o['active'] ? ' désactivé' : ' activé') . '.';
            }
            $screen = tg_screen_operators();
            break;
        case 'sb': $screen = tg_screen_subscription(); break;
        case 'mc': $screen = tg_screen_merchants(); break;
        case 'ma':
            $mm = is_admin_ctx() ? q_one("SELECT * FROM merchants WHERE id = ? AND kind = 'tenant'", [(int)$arg]) : null;
            if ($mm && $mm['status'] !== 'active') {
                db_update('merchants', ['status' => 'active', 'active' => 1], 'id = :id', ['id' => $mm['id']]);
                push_notify('✅ Compte validé', 'Vous pouvez générer votre clé API et encaisser vos premiers paiements.', 'api', 'approved', (int)$mm['id']);
                $toast = 'Compte « ' . $mm['name'] . ' » validé.';
            }
            $screen = tg_screen_merchants();
            break;
        case 'nw':
            tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
            tg_ask_amount($chat['id'], $cb['from'] ?? [], ($chat['type'] ?? '') === 'private');
            return;
        default:
            $screen = tg_screen_menu();
    }
    tg_api('answerCallbackQuery', array_filter(['callback_query_id' => $cb['id'], 'text' => $toast ? mb_substr($toast, 0, 190) : null]));
    tg_api('editMessageText', [
        'chat_id' => $chat['id'],
        'message_id' => $msg['message_id'],
        'text' => $screen[0],
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($screen[1]),
    ]);
}

/* ------------------------------------------------------------------
 *  Liaison et autorisations
 * ------------------------------------------------------------------ */

/** Charge la discussion reliée et place le contexte (admin ou marchand) pour les requêtes. */
function tg_load_chat($chatId): ?array
{
    $row = q_one('SELECT * FROM telegram_chats WHERE chat_id = ?', [(string)$chatId]);
    if (!$row) {
        return null;
    }
    if ($row['merchant_id'] === null) {
        ctx_set('admin');
        return $row;
    }
    $m = q_one("SELECT * FROM merchants WHERE id = ? AND kind = 'tenant'", [(int)$row['merchant_id']]);
    if (!$m || $m['status'] === 'suspended') {
        return null;
    }
    ctx_set('merchant', $m);
    return $row;
}

function tg_do_link(array $chat, array $from, string $code): void
{
    $answer = tg_link_chat($chat, $from, $code);
    tg_send($chat['id'], $answer);
    if (str_starts_with($answer, '✅')) {
        if (tg_load_chat($chat['id'])) {
            tg_reply_screen($chat['id'], tg_screen_menu());
        } else {
            log_line('telegram: liaison enregistrée mais introuvable pour ' . $chat['id']);
            tg_send($chat['id'], '⚠️ La liaison n\'a pas pu être relue. Réessayez avec un nouveau code ; si cela continue, contactez le support.');
        }
    }
}

function tg_link_chat(array $chat, array $from, string $code): string
{
    $link = q_one('SELECT * FROM telegram_links WHERE code = ?', [$code]);
    if (!$link || $link['expires_at'] < now()) {
        return '❌ Code invalide ou expiré. Générez un nouveau code dans votre espace (menu <b>Telegram</b>).';
    }
    $private = ($chat['type'] ?? '') === 'private';
    if (!$private && !tg_is_chat_admin($chat['id'], (int)($from['id'] ?? 0))) {
        return '⛔ Seul un administrateur du groupe peut relier ce groupe.';
    }
    q('DELETE FROM telegram_links WHERE code = ?', [$code]);
    q('DELETE FROM telegram_chats WHERE chat_id = ?', [(string)$chat['id']]);
    $title = $private
        ? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) . (!empty($chat['username']) ? ' (@' . $chat['username'] . ')' : '')
        : (string)($chat['title'] ?? 'Groupe');
    db_insert('telegram_chats', [
        'chat_id' => (string)$chat['id'],
        'merchant_id' => $link['merchant_id'],
        'title' => mb_substr($title ?: 'Discussion', 0, 190),
        'chat_type' => mb_substr((string)($chat['type'] ?? ''), 0, 20),
        'notify' => 1,
        'can_act' => (int)$link['can_act'],
        'created_at' => now(),
    ]);
    $who = $link['merchant_id'] === null
        ? 'l\'administration de ' . app_name()
        : 'l\'espace « ' . (string)q_val('SELECT name FROM merchants WHERE id = ?', [$link['merchant_id']]) . ' »';
    return '✅ ' . ($private ? 'Cette discussion est reliée' : 'Ce groupe est relié') . ' à ' . tg_h($who) . ".\n"
        . '🔔 Chaque paiement reçu sera annoncé ici.'
        . ((int)$link['can_act'] ? '' : "\n👁 Mode lecture seule : consultation uniquement.")
        . (!$private && (int)$link['can_act'] ? "\n🔐 Dans un groupe, seuls les administrateurs du groupe peuvent valider, annuler ou créer des paiements." : '');
}

function tg_is_chat_admin($chatId, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $r = tg_api('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId], 6);
    return in_array($r['result']['status'] ?? '', ['creator', 'administrator'], true);
}

/** Message d'erreur si l'utilisateur n'a pas le droit d'agir, null sinon. */
function tg_deny(array $actor): ?string
{
    if (!(int)$actor['row']['can_act']) {
        return '👁 Cette discussion est en lecture seule (modifiable dans votre espace, menu Telegram).';
    }
    if (($actor['chat']['type'] ?? '') !== 'private' && !tg_is_chat_admin($actor['chat']['id'], (int)($actor['from']['id'] ?? 0))) {
        return '⛔ Dans un groupe, seuls les administrateurs du groupe peuvent faire cette action.';
    }
    return null;
}

/* ------------------------------------------------------------------
 *  Envoi
 * ------------------------------------------------------------------ */

/** Clavier de boutons : [[ [texte, donnée ou https://…], … ], …] */
function tg_kb(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($row as [$label, $data]) {
            $line[] = str_starts_with((string)$data, 'https://') ? ['text' => $label, 'url' => $data] : ['text' => $label, 'callback_data' => (string)$data];
        }
        if ($line) {
            $out[] = $line;
        }
    }
    return ['inline_keyboard' => $out];
}

function tg_send($chatId, string $html, array $rows = []): array
{
    return tg_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $html,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => tg_kb($rows),
    ]);
}

function tg_reply_screen($chatId, array $screen): void
{
    tg_send($chatId, $screen[0], $screen[1]);
}

function tg_ask_amount($chatId, array $from = [], bool $private = true): void
{
    // Dans un groupe, on mentionne la personne : sa réponse est alors automatiquement liée à cette question.
    $who = !$private && !empty($from['id'])
        ? '<a href="tg://user?id=' . (int)$from['id'] . '">' . tg_h($from['first_name'] ?? 'Vous') . '</a>, '
        : '';
    tg_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => "➕ Nouveau paiement\n" . $who . "envoyez le <b>montant</b> (et si vous voulez le numéro du client et une description).\n"
            . "Ex. : <code>5000</code> ou <code>5000 70123456 Recharge</code>\n"
            . "<i>Ou en une fois : /creer 5000</i>",
        'parse_mode' => 'HTML',
        'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'Montant, ex. 5000', 'selective' => !$private],
    ]);
}

function tg_dash_url(string $path = ''): ?string
{
    $u = abs_url(ctx()['base'] . ($path !== '' ? '/' . $path : ''));
    return str_starts_with($u, 'https://') ? $u : null;
}

/* ------------------------------------------------------------------
 *  Écrans : [texte HTML, boutons]
 * ------------------------------------------------------------------ */

function tg_scope_label(): string
{
    return is_admin_ctx() ? app_name() . ' · Admin' : (string)(ctx()['merchant']['name'] ?? '');
}

function tg_screen_menu(): array
{
    $pp = [];
    $pending = (int)q_val("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND " . list_scope_sql('merchant_id', $pp), $pp);
    $sp = [];
    $review = (int)q_val("SELECT COUNT(*) FROM sms_messages WHERE status IN ('unmatched','review') AND " . list_scope_sql('merchant_id', $sp), $sp);
    $today = tg_day_start(0);
    $tp = [$today];
    $sum = q_one("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS t FROM payments WHERE status = 'paid' AND paid_at >= ? AND " . list_scope_sql('merchant_id', $tp), $tp);
    $text = '🏠 <b>' . tg_h(tg_scope_label()) . "</b>\n\n"
        . '💰 Aujourd\'hui : <b>' . tg_h(fmt_amount($sum['t'], 'F')) . '</b> (' . (int)$sum['n'] . " paiement" . ((int)$sum['n'] > 1 ? 's' : '') . ")\n"
        . "⏳ En attente : <b>$pending</b>\n"
        . "🔎 SMS à traiter : <b>$review</b>";
    $rows = [
        [['📊 Statistiques', 'st'], ['💳 Paiements', 'pl:0']],
        [['⏳ En attente' . ($pending ? " ($pending)" : ''), 'pe'], ['🔎 SMS' . ($review ? " ($review)" : ''), 'sm']],
        [['📱 Téléphones', 'dv'], ['🏦 Opérateurs', 'op']],
        [['💸 Envois & remboursements', 'ol']],
        [is_admin_ctx() ? ['🏪 Marchands', 'mc'] : ['📅 Abonnement', 'sb'], ['➕ Créer un paiement', 'nw']],
    ];
    if ($u = tg_dash_url()) {
        $rows[] = [['🌐 Ouvrir le tableau de bord', $u]];
    }
    return [$text, $rows];
}

/** Début du jour local (fuseau de la plateforme) il y a $daysAgo jours, en UTC. */
function tg_day_start(int $daysAgo): string
{
    $tz = new DateTimeZone((string)setting('timezone', 'Africa/Ouagadougou'));
    $d = new DateTime('today', $tz);
    if ($daysAgo) {
        $d->modify("-$daysAgo days");
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function tg_screen_stats(): array
{
    $sum = function (string $from, ?string $to = null): array {
        $p = [$from];
        $w = 'paid_at >= ?';
        if ($to) {
            $w .= ' AND paid_at < ?';
            $p[] = $to;
        }
        $r = q_one("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS t FROM payments WHERE status = 'paid' AND $w AND " . list_scope_sql('merchant_id', $p), $p);
        return [(int)$r['n'], (float)$r['t']];
    };
    [$nT, $tT] = $sum(tg_day_start(0));
    [$nY, $tY] = $sum(tg_day_start(1), tg_day_start(0));
    [$nW, $tW] = $sum(tg_day_start(6));
    [$nM, $tM] = $sum(tg_day_start(29));
    $p = [tg_day_start(6)];
    $closed = q_one("SELECT SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS ok, COUNT(*) AS n FROM payments WHERE status IN ('paid','expired','cancelled') AND created_at >= ? AND " . list_scope_sql('merchant_id', $p), $p);
    $rate = $closed && (int)$closed['n'] > 0 ? round(100 * (int)$closed['ok'] / (int)$closed['n']) . ' %' : '—';
    $byOp = [];
    $p = [tg_day_start(6)];
    foreach (q_all("SELECT paid_operator AS op, COUNT(*) AS n, COALESCE(SUM(amount),0) AS t FROM payments WHERE status = 'paid' AND paid_at >= ? AND " . list_scope_sql('merchant_id', $p) . ' GROUP BY paid_operator ORDER BY t DESC', $p) as $r) {
        $o = $r['op'] ? operator_by_code($r['op']) : null;
        $byOp[] = '   • ' . tg_h($o['name'] ?? ($r['op'] ?: 'Validé à la main')) . ' : ' . tg_h(fmt_amount($r['t'], 'F')) . ' (' . (int)$r['n'] . ')';
    }
    $f = fn(int $n, float $t) => '<b>' . tg_h(fmt_amount($t, 'F')) . '</b> · ' . $n . ' paiement' . ($n > 1 ? 's' : '');
    $text = '📊 <b>Statistiques</b> · ' . tg_h(tg_scope_label()) . "\n\n"
        . "Aujourd'hui : " . $f($nT, $tT) . "\n"
        . 'Hier : ' . $f($nY, $tY) . "\n"
        . '7 derniers jours : ' . $f($nW, $tW) . "\n"
        . '30 derniers jours : ' . $f($nM, $tM) . "\n"
        . "Taux de réussite (7 j) : <b>$rate</b>"
        . ($byOp ? "\n\nPar opérateur (7 j) :\n" . implode("\n", $byOp) : '')
        . "\n\n<i>Mis à jour à " . tg_h(substr(fmt_dt(now()), 11, 5)) . '</i>';
    return [$text, [[['🔄 Actualiser', 'st'], ['⬅️ Menu', 'm']]]];
}

function tg_status_emoji(string $s): string
{
    return ['paid' => '✅', 'pending' => '⏳', 'expired' => '⌛', 'cancelled' => '❌'][$s] ?? '•';
}

function tg_phone_mask(?string $phone): string
{
    $d = $phone ? substr(preg_replace('/\D+/', '', $phone), -8) : '';
    return $d !== '' ? $d : 'n° non saisi';
}

function tg_payment_line(array $p): string
{
    return tg_status_emoji($p['status']) . ' <b>' . tg_h(fmt_amount($p['amount'], 'F')) . '</b> · ' . tg_h(tg_phone_mask($p['payer_phone']))
        . ' · ' . tg_h(substr(fmt_dt($p['paid_at'] ?: $p['created_at']), 0, 16))
        . "\n     <code>" . tg_h($p['reference']) . '</code>' . (!empty($p['merchant_name']) && is_admin_ctx() ? ' · ' . tg_h($p['merchant_name']) : '');
}

function tg_screen_payments(int $page): array
{
    $per = 8;
    $params = [];
    $where = list_scope_sql('p.merchant_id', $params);
    $total = (int)q_val("SELECT COUNT(*) FROM payments p WHERE $where", $params);
    $rows = q_all("SELECT p.*, m.name AS merchant_name FROM payments p LEFT JOIN merchants m ON m.id = p.merchant_id WHERE $where ORDER BY p.id DESC LIMIT $per OFFSET " . ($page * $per), $params);
    if (!$rows) {
        return ['💳 <b>Paiements</b>' . "\n\nAucun paiement pour l'instant.", [[['➕ Créer un paiement', 'nw'], ['⬅️ Menu', 'm']]]];
    }
    $text = '💳 <b>Paiements</b> (' . ($page * $per + 1) . '–' . ($page * $per + count($rows)) . " sur $total)\n\n" . implode("\n", array_map('tg_payment_line', $rows));
    $btns = [];
    foreach (array_chunk($rows, 2) as $pair) {
        $btns[] = array_map(fn($p) => [tg_status_emoji($p['status']) . ' ' . fmt_amount($p['amount'], 'F') . ' · ' . substr($p['reference'], -4), 'pd:' . $p['reference']], $pair);
    }
    $nav = [];
    if ($page > 0) {
        $nav[] = ['◀️ Plus récents', 'pl:' . ($page - 1)];
    }
    if (($page + 1) * $per < $total) {
        $nav[] = ['Plus anciens ▶️', 'pl:' . ($page + 1)];
    }
    if ($nav) {
        $btns[] = $nav;
    }
    $btns[] = [['🔄 Actualiser', 'pl:' . $page], ['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_screen_pending(): array
{
    $params = [];
    $rows = q_all("SELECT p.*, m.name AS merchant_name FROM payments p LEFT JOIN merchants m ON m.id = p.merchant_id WHERE p.status = 'pending' AND " . list_scope_sql('p.merchant_id', $params) . ' ORDER BY p.id DESC LIMIT 15', $params);
    if (!$rows) {
        return ["⏳ <b>En attente</b>\n\nAucun paiement en attente. 👌", [[['🔄 Actualiser', 'pe'], ['⬅️ Menu', 'm']]]];
    }
    $text = '⏳ <b>Paiements en attente</b> (' . count($rows) . ")\n\n" . implode("\n", array_map(fn($p) => tg_payment_line($p) . ' · expire ' . tg_h(substr(fmt_dt($p['expires_at']), 11, 5)), $rows));
    $btns = [];
    foreach (array_chunk($rows, 2) as $pair) {
        $btns[] = array_map(fn($p) => ['⏳ ' . fmt_amount($p['amount'], 'F') . ' · ' . substr($p['reference'], -4), 'pd:' . $p['reference']], $pair);
    }
    $btns[] = [['🔄 Actualiser', 'pe'], ['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_find_payment(string $ref): ?array
{
    $p = $ref !== '' ? payment_find($ref) : null;
    if ($p && !is_admin_ctx() && (int)$p['merchant_id'] !== ctx_mid()) {
        return null; // un marchand ne voit que ses paiements
    }
    return $p;
}

function tg_find_sms(int $id): ?array
{
    $s = $id > 0 ? q_one('SELECT * FROM sms_messages WHERE id = ?', [$id]) : null;
    if ($s && !is_admin_ctx() && (int)$s['merchant_id'] !== ctx_mid()) {
        return null;
    }
    return $s;
}

function tg_screen_payment(string $ref): array
{
    $p = tg_find_payment($ref);
    if (!$p) {
        return ['❌ Paiement <code>' . tg_h($ref) . '</code> introuvable.', [[['💳 Paiements', 'pl:0'], ['⬅️ Menu', 'm']]]];
    }
    $op = $p['paid_operator'] ? operator_by_code($p['paid_operator'], payment_scope($p)) : (!empty($p['operator_id']) ? operator_by_id($p['operator_id']) : null);
    $merchant = $p['merchant_id'] ? q_val('SELECT name FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
    $lines = [
        tg_status_emoji($p['status']) . ' <b>' . tg_h(fmt_amount($p['amount'], 'F')) . '</b> · ' . tg_h(status_label($p['status'])),
        'Référence : <code>' . tg_h($p['reference']) . '</code>',
    ];
    if ($p['external_ref']) {
        $lines[] = 'Votre réf. : <code>' . tg_h($p['external_ref']) . '</code>';
    }
    if ($merchant && is_admin_ctx()) {
        $lines[] = 'Marchand : ' . tg_h($merchant);
    }
    $lines[] = 'Client : ' . tg_h($p['payer_phone'] ?: 'numéro pas encore saisi');
    if ($op) {
        $lines[] = 'Opérateur : ' . tg_h($op['name']);
    }
    if ($p['description']) {
        $lines[] = 'Description : ' . tg_h($p['description']);
    }
    $lines[] = 'Créé : ' . tg_h(fmt_dt($p['created_at']));
    if ($p['status'] === 'paid') {
        $lines[] = 'Payé : ' . tg_h(fmt_dt($p['paid_at']));
        if ($p['operator_txn_id']) {
            $lines[] = 'Transaction : <code>' . tg_h($p['operator_txn_id']) . '</code>';
        }
    } elseif ($p['status'] === 'pending') {
        $lines[] = 'Expire : ' . tg_h(fmt_dt($p['expires_at']));
        $lines[] = "\n🔗 Lien à envoyer au client :\n" . tg_h(abs_url('pay/' . $p['reference']));
    }
    $btns = [];
    $act = [];
    if (in_array($p['status'], ['pending', 'expired'], true)) {
        $act[] = ['✅ Marquer payé', 'pk:' . $p['reference']];
    }
    if ($p['status'] === 'pending') {
        $act[] = ['❌ Annuler', 'pc:' . $p['reference']];
    }
    if ($p['status'] === 'paid' && payouts_enabled()) {
        $act[] = ['↩️ Rembourser', 'rf:' . $p['reference']];
    }
    if ($act) {
        $btns[] = $act;
    }
    $checkout = abs_url('pay/' . $p['reference']);
    $open = [];
    if ($p['status'] === 'pending' && str_starts_with($checkout, 'https://')) {
        $open[] = ['🔗 Page de paiement', $checkout];
    }
    if ($u = tg_dash_url('payments/' . $p['reference'])) {
        $open[] = ['🌐 Dans l\'espace', $u];
    }
    if ($open) {
        $btns[] = $open;
    }
    $btns[] = [['🔄 Actualiser', 'pd:' . $p['reference']], ['💳 Paiements', 'pl:0'], ['⬅️ Menu', 'm']];
    return [implode("\n", $lines), $btns];
}

function tg_screen_confirm(string $ref, string $question, string $op, string $yes): array
{
    $p = tg_find_payment($ref);
    if (!$p) {
        return tg_screen_payment($ref);
    }
    return [
        '⚠️ ' . $question . "\n\n" . tg_payment_line($p),
        [[[$yes, $op . ':' . $p['reference']], ['↩️ Non', 'pd:' . $p['reference']]]],
    ];
}

function tg_create_payment(string $args): array
{
    $tok = preg_split('/\s+/', trim($args));
    // Accepte « 5000 », « 5 000 » (avec espace) ou « 5.000 »
    $amountTxt = array_shift($tok);
    while ($tok && preg_match('/^\d{3}$/', $tok[0]) && preg_match('/^\d{1,3}$/', $amountTxt)) {
        $amountTxt .= array_shift($tok);
    }
    $amount = preg_replace('/[^\d]/', '', (string)$amountTxt);
    $phone = null;
    if ($tok && preg_match('/^\+?\d[\d ]{7,}$/', $tok[0])) {
        $phone = array_shift($tok);
    }
    $desc = trim(implode(' ', $tok));
    $merchant = is_admin_ctx() ? null : ctx()['merchant'];
    try {
        $p = payment_create([
            'amount' => $amount === '' ? null : $amount,
            'payer_phone' => $phone,
            'description' => $desc !== '' ? $desc : 'Paiement créé depuis Telegram',
        ], $merchant);
    } catch (Throwable $e) {
        if (!$e instanceof InvalidArgumentException) {
            log_line('telegram creer: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        return ['❌ ' . tg_h(ucfirst((string)preg_replace(['/^amount: /', '/^payer_phone: /', '/^\w+: /'], ['Montant : ', 'Numéro du client : ', ''], $e->getMessage()))) . "\n\nExemple : <code>/creer 5000 70123456 Recharge</code>", [[['➕ Réessayer', 'nw'], ['⬅️ Menu', 'm']]]];
    }
    [$text, $btns] = tg_screen_payment($p['reference']);
    return ["✅ <b>Paiement créé</b> : transférez le lien au client.\nVous serez prévenu ici dès qu'il aura payé.\n\n" . $text, $btns];
}

function tg_screen_sms(): array
{
    $params = [];
    $rows = q_all("SELECT * FROM sms_messages WHERE status IN ('review','unmatched') AND " . list_scope_sql('merchant_id', $params) . ' ORDER BY id DESC LIMIT 10', $params);
    if (!$rows) {
        return ["🔎 <b>SMS</b>\n\nAucun SMS à vérifier ni d'argent reçu sans paiement. 👌", [[['🔄 Actualiser', 'sm'], ['⬅️ Menu', 'm']]]];
    }
    $lines = [];
    $btns = [];
    foreach ($rows as $s) {
        $op = $s['operator'] ? operator_by_code($s['operator'], $s['merchant_id'] !== null ? (int)$s['merchant_id'] : null) : null;
        $lines[] = ($s['status'] === 'review' ? '🔎' : '⚠️') . ' <b>#' . (int)$s['id'] . '</b> · ' . tg_h(fmt_amount($s['amount'] ?? 0, 'F'))
            . ' · ' . tg_h($s['phone'] ?: 'n° inconnu') . ($op ? ' · ' . tg_h($op['name']) : '')
            . "\n     " . tg_h(status_label($s['status'])) . ' · ' . tg_h(substr(fmt_dt($s['created_at']), 0, 16))
            . ($s['note'] ? "\n     <i>" . tg_h($s['note']) . '</i>' : '');
        $line = [];
        if ($s['status'] === 'review') {
            $line[] = ['✅ #' . $s['id'] . ' Argent bien reçu', 'sa:' . $s['id']];
        }
        $line[] = ['🚫 #' . $s['id'] . ' Ignorer', 'si:' . $s['id']];
        $btns[] = $line;
    }
    $text = '🔎 <b>SMS à traiter</b> (' . count($rows) . ")\n\n" . implode("\n\n", $lines)
        . "\n\n🔎 <i>À vérifier</i> : solde incohérent, possible faux SMS. Vérifiez sur le téléphone avant de valider."
        . "\n⚠️ <i>Non rapproché</i> : argent reçu sans paiement correspondant. Pour le relier : <code>/rapprocher 12 PAY-XXXXXXXXXX</code>";
    $btns[] = [['🔄 Actualiser', 'sm'], ['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_screen_devices(): array
{
    $params = [];
    $rows = q_all('SELECT * FROM devices WHERE active = 1 AND ' . scope_sql('merchant_id', current_scope(), $params) . ' ORDER BY id', $params);
    if (!$rows) {
        return ["📱 <b>Téléphones</b>\n\nAucun téléphone passerelle. Ajoutez-en un dans l'espace (menu Téléphones).", [[['⬅️ Menu', 'm']]]];
    }
    $lines = [];
    foreach ($rows as $d) {
        $ios = ($d['kind'] ?? 'android') === 'ios';
        $on = device_online($d);
        $l = ($on ? '🟢' : '🔴') . ' <b>' . tg_h($d['name']) . '</b>' . ($d['sim_number'] ? ' · ' . tg_h($d['sim_number']) : '') . ($ios ? ' · iPhone' : '');
        if (!$ios) {
            $l .= "\n     " . ($on ? 'En ligne' : 'Hors ligne') . ' · vu ' . tg_h($d['last_seen_at'] ? substr(fmt_dt($d['last_seen_at']), 0, 16) : 'jamais')
                . ($d['battery'] !== null ? ' · 🔋 ' . (int)$d['battery'] . ' %' . ((int)$d['charging'] ? ' ⚡' : '') : '')
                . ($d['app_version'] ? ' · v' . tg_h($d['app_version']) : '');
        }
        foreach (device_balances($d) as $code => $bal) {
            $o = operator_by_code((string)$code);
            if ($bal !== null) {
                $l .= "\n     💼 " . tg_h($o['name'] ?? $code) . ' : ' . tg_h(fmt_amount($bal, 'F'));
            }
        }
        if (empty($d['token_hash'])) {
            $l .= "\n     <i>Pas encore associé (code : " . tg_h($d['pair_code'] ?: '—') . ')</i>';
        }
        $lines[] = $l;
    }
    $btns = [[['🔄 Actualiser', 'dv'], ['⬅️ Menu', 'm']]];
    if ($u = tg_dash_url('devices')) {
        array_unshift($btns, [['🌐 Gérer les téléphones', $u]]);
    }
    return ["📱 <b>Téléphones passerelle</b>\n\n" . implode("\n\n", $lines), $btns];
}

function tg_screen_operators(): array
{
    $ops = operators(false, current_scope());
    if (!$ops) {
        return ["🏦 <b>Opérateurs</b>\n\nAucun opérateur configuré.", [[['⬅️ Menu', 'm']]]];
    }
    $lines = [];
    $btns = [];
    foreach ($ops as $o) {
        $state = !(int)$o['active'] ? '⚪ Désactivé' : (operator_ready($o) ? '🟢 Actif' : '🟠 Numéro manquant');
        $fee = (float)($o['fee_percent'] ?? 0);
        $lines[] = '<b>' . tg_h($o['name']) . '</b> · ' . $state
            . "\n     Numéro : " . tg_h($o['merchant_number'] ?: '—') . ($fee > 0 ? ' · frais client ' . tg_h(rtrim(rtrim(number_format($fee, 2, ',', ''), '0'), ',')) . ' %' : '');
        $btns[] = [[((int)$o['active'] ? '⏸ Désactiver ' : '▶️ Activer ') . $o['name'], 'ot:' . $o['id']]];
    }
    $btns[] = [['🔄 Actualiser', 'op'], ['⬅️ Menu', 'm']];
    return ["🏦 <b>Opérateurs</b>\n\n" . implode("\n\n", $lines) . "\n\n<i>Numéros, codes USSD et frais se modifient dans l'espace web.</i>", $btns];
}

function tg_screen_subscription(): array
{
    if (is_admin_ctx()) {
        return tg_screen_merchants();
    }
    $m = q_one('SELECT * FROM merchants WHERE id = ?', [ctx_mid()]);
    $btns = [];
    if (!subscription_enabled()) {
        $text = "📅 <b>Abonnement</b>\n\nAucun abonnement n'est demandé pour l'instant.";
    } else {
        $left = subscription_days_left($m);
        $text = "📅 <b>Abonnement</b>\n\n"
            . ($left > 0
                ? '✅ Actif jusqu\'au <b>' . tg_h(substr(fmt_dt($m['paid_until']), 0, 10)) . "</b> ($left jour" . ($left > 1 ? 's' : '') . ' restant' . ($left > 1 ? 's' : '') . ')'
                : '⛔ <b>Inactif</b> : vous ne pouvez pas encaisser tant que l\'abonnement n\'est pas payé.')
            . "\n\nPrix : " . tg_h(fmt_amount(subscription_price(), 'F')) . ' pour ' . subscription_days() . ' jours.';
        if ($u = tg_dash_url('subscription')) {
            $btns[] = [[$left > 0 ? '🔁 Renouveler' : '💳 Payer l\'abonnement', $u]];
        }
    }
    $btns[] = [['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_screen_merchants(): array
{
    if (!is_admin_ctx()) {
        return tg_screen_subscription();
    }
    $pending = q_all("SELECT * FROM merchants WHERE kind = 'tenant' AND status = 'pending' ORDER BY id DESC LIMIT 10");
    $active = (int)q_val("SELECT COUNT(*) FROM merchants WHERE kind = 'tenant' AND status = 'active'");
    $paying = (int)q_val("SELECT COUNT(*) FROM merchants WHERE kind = 'tenant' AND status = 'active' AND paid_until >= ?", [now()]);
    $soon = q_all("SELECT * FROM merchants WHERE kind = 'tenant' AND status = 'active' AND paid_until >= ? AND paid_until < ? ORDER BY paid_until LIMIT 10", [now(), ts_plus(5 * 86400)]);
    $text = "🏪 <b>Marchands inscrits</b>\n\n"
        . "Comptes actifs : <b>$active</b> · abonnement payé : <b>$paying</b>\n";
    $btns = [];
    if ($pending) {
        $text .= "\n🆕 <b>En attente de validation</b> :\n";
        foreach ($pending as $m) {
            $text .= '   • ' . tg_h($m['name']) . ($m['email'] ? ' · ' . tg_h($m['email']) : '') . ($m['phone'] ? ' · ' . tg_h($m['phone']) : '') . ' · ' . tg_h(substr(fmt_dt($m['created_at']), 0, 10)) . "\n";
            $btns[] = [['✅ Valider ' . mb_substr($m['name'], 0, 30), 'ma:' . $m['id']]];
        }
    } else {
        $text .= "\nAucun compte en attente de validation.\n";
    }
    if ($soon) {
        $text .= "\n⏰ <b>Abonnements qui expirent bientôt</b> :\n";
        foreach ($soon as $m) {
            $text .= '   • ' . tg_h($m['name']) . ' · ' . tg_h(substr(fmt_dt($m['paid_until']), 0, 10)) . "\n";
        }
    }
    if ($u = tg_dash_url('merchants')) {
        $btns[] = [['🌐 Gérer les marchands', $u]];
    }
    $btns[] = [['🔄 Actualiser', 'mc'], ['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_help_unlinked(): string
{
    return '👋 Bonjour ! Je suis le bot de <b>' . tg_h(app_name()) . "</b>.\n\n"
        . "Pour recevoir vos paiements ici :\n"
        . "1. Connectez-vous à votre espace sur le site\n"
        . "2. Ouvrez le menu <b>Telegram</b> et générez un code\n"
        . "3. Touchez le lien proposé, ou <b>envoyez-moi simplement le code</b> (ex. <code>FP2K6HGN6X</code>)\n\n"
        . 'Pour un groupe : ajoutez-moi au groupe, puis un administrateur du groupe envoie <code>/lier VOTRECODE</code>.';
}

function tg_help(): string
{
    return '🤖 <b>' . tg_h(app_name()) . "</b> · commandes\n\n"
        . "/menu : tableau de bord avec boutons\n"
        . "/stats : encaissements (jour, semaine, mois)\n"
        . "/paiements : derniers paiements\n"
        . "/attente : paiements en attente\n"
        . "/creer 5000 [numéro] [description] : créer un lien de paiement\n"
        . "/paiement PAY-… : détail, valider ou annuler\n"
        . "/sms : SMS à vérifier et argent reçu sans paiement\n"
        . "/rapprocher 12 PAY-… : relier un SMS à un paiement\n"
        . "/envoyer 5000 70112233 orange : envoyer de l'argent\n"
        . "/rembourser PAY-… [montant] : rembourser un client\n"
        . "/envois : envois et remboursements (valider, suivre)\n"
        . "/telephones : état des téléphones et soldes\n"
        . "/operateurs : opérateurs (activer / désactiver)\n"
        . (is_admin_ctx() ? "/marchands : comptes à valider, abonnements\n" : "/abonnement : état de votre abonnement\n")
        . "/notifs : couper / remettre les alertes ici\n"
        . "/delier : délier cette discussion\n\n"
        . '🔔 Les alertes arrivent ici automatiquement : paiement reçu, SMS à vérifier, argent reçu sans paiement…';
}

/* ------------------------------------------------------------------
 *  Annonces : mises à jour de l'application, informations aux marchands
 * ------------------------------------------------------------------ */

/**
 * Envoie un message à toutes les discussions reliées.
 * $audience : 'all' (tout le monde), 'admin' (discussions de l'administration), 'merchants' (marchands inscrits).
 */
function tg_broadcast(string $html, array $buttons = [], string $audience = 'all'): int
{
    if (!tg_enabled()) {
        return 0;
    }
    $where = ['all' => '1=1', 'admin' => 'merchant_id IS NULL', 'merchants' => 'merchant_id IS NOT NULL'][$audience] ?? '1=1';
    $sent = 0;
    foreach (q_all("SELECT * FROM telegram_chats WHERE $where ORDER BY id") as $c) {
        $r = tg_api('sendMessage', [
            'chat_id' => $c['chat_id'],
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => tg_kb($buttons ? [$buttons] : []),
        ], 6);
        if (!empty($r['ok'])) {
            $sent++;
        } elseif (preg_match('/blocked|kicked|not found|deactivated|not a member/i', (string)($r['description'] ?? ''))) {
            q('DELETE FROM telegram_chats WHERE id = ?', [$c['id']]);
        }
        usleep(50000); // limite de Telegram : ~30 messages par seconde
    }
    return $sent;
}

/** Envoie une annonce et la garde dans l'historique. */
function announce(string $title, string $body, ?string $link, string $linkLabel = '📥 Télécharger', string $audience = 'all'): int
{
    $html = '📢 <b>' . tg_h($title) . '</b>' . (trim($body) !== '' ? "\n\n" . tg_h(trim($body)) : '')
        . ($link ? "\n\n🔗 " . tg_h($link) : '');
    $buttons = $link && str_starts_with($link, 'https://') ? [[$linkLabel ?: '🔗 Ouvrir', $link]] : [];
    $sent = tg_broadcast($html, $buttons, $audience);
    db_insert('announcements', [
        'title' => mb_substr($title, 0, 190),
        'body' => $body,
        'link' => $link ? mb_substr($link, 0, 500) : null,
        'audience' => $audience,
        'sent' => $sent,
        'created_at' => now(),
    ]);
    return $sent;
}

/** Dernière version de l'application publiée par GitHub (downloads/version.json), ou null. */
function app_release_info(): ?array
{
    $f = APP_ROOT . '/downloads/version.json';
    if (!is_file($f)) {
        return null;
    }
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j) || empty($j['version'])) {
        return null;
    }
    $j['url'] = abs_url('downloads/' . ($j['file'] ?? 'smspay-gateway.apk'));
    return $j;
}

/** Texte de l'annonce d'une nouvelle version de l'application. */
function app_release_message(array $r): array
{
    $notes = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($r['notes'] ?? '')))));
    $body = $notes ? "Ce qui change :\n" . implode("\n", array_map(fn($l) => '• ' . ltrim($l, "-•* "), $notes)) : '';
    $body .= "\n\nComment mettre à jour : ouvrez le lien sur le téléphone passerelle, puis installez le fichier.";
    if (empty($r['fixed_signature'])) {
        $body .= "\n⚠️ Pour cette version, désinstallez d'abord l'ancienne application, installez la nouvelle, puis associez-la de nouveau (Téléphones > Nouveau code) et réactivez l'accès aux notifications.";
    }
    return ['🆕 Mise à jour de l\'application passerelle : version ' . $r['version'], trim($body)];
}

/** Annonce automatiquement une nouvelle version de l'application (appelé par les tâches périodiques). */
function app_release_check(bool $force = false): bool
{
    try {
        $r = app_release_info();
        if (!$r || !tg_enabled() || (!$force && setting('auto_announce_app', '1') !== '1')) {
            return false;
        }
        $key = $r['version'] . '#' . ($r['code'] ?? '') . '#' . ($r['commit'] ?? '');
        if (!$force && setting('app_release_announced') === $key) {
            return false;
        }
        setting_set('app_release_announced', $key); // d'abord : jamais deux annonces pour la même version
        [$title, $body] = app_release_message($r);
        announce($title, $body, $r['url'], '📥 Télécharger la version ' . $r['version']);
        return true;
    } catch (Throwable $e) {
        log_line('annonce version: ' . $e->getMessage());
        return false;
    }
}

/* ------------------------------------------------------------------
 *  Envois d'argent et remboursements
 * ------------------------------------------------------------------ */

function tg_find_payout(string $ref): ?array
{
    $o = $ref !== '' ? payout_find($ref) : null;
    if ($o && !is_admin_ctx() && (int)$o['merchant_id'] !== ctx_mid()) {
        return null;
    }
    return $o;
}

function tg_payout_line(array $o): string
{
    $op = operator_by_code($o['operator'], $o['scope_id'] !== null ? (int)$o['scope_id'] : null);
    return payout_emoji($o['status']) . ' <b>' . tg_h(fmt_amount($o['amount'], 'F')) . '</b> → ' . tg_h($o['phone']) . ' · ' . tg_h($op['name'] ?? $o['operator'])
        . "\n     <code>" . tg_h($o['reference']) . '</code> · ' . tg_h(payout_status_label($o['status'])) . ' · ' . tg_h(substr(fmt_dt($o['created_at']), 0, 16));
}

function tg_screen_payouts(): array
{
    $params = [];
    $where = is_admin_ctx() ? '1=1' : list_scope_sql('merchant_id', $params);
    $rows = q_all("SELECT * FROM payouts WHERE $where ORDER BY CASE WHEN status IN ('approval','unknown') THEN 0 ELSE 1 END, id DESC LIMIT 10", $params);
    $text = "💸 <b>Envois & remboursements</b>\n\n";
    $btns = [];
    if (!$rows) {
        $text .= "Aucun envoi pour l'instant.";
    } else {
        $text .= implode("\n", array_map('tg_payout_line', $rows));
        foreach (array_chunk($rows, 2) as $pair) {
            $btns[] = array_map(fn($o) => [payout_emoji($o['status']) . ' ' . fmt_amount($o['amount'], 'F') . ' · ' . substr($o['reference'], -4), 'od:' . $o['reference']], $pair);
        }
    }
    $text .= "\n\n<i>Nouvel envoi : /envoyer 5000 70112233 orange\nRemboursement : bouton « ↩️ Rembourser » sur un paiement payé.</i>";
    $btns[] = [['🔄 Actualiser', 'ol'], ['⬅️ Menu', 'm']];
    return [$text, $btns];
}

function tg_screen_payout(string $ref): array
{
    $o = tg_find_payout($ref);
    if (!$o) {
        return ['❌ Envoi <code>' . tg_h($ref) . '</code> introuvable.', [[['💸 Envois', 'ol'], ['⬅️ Menu', 'm']]]];
    }
    $op = operator_by_code($o['operator'], $o['scope_id'] !== null ? (int)$o['scope_id'] : null);
    $lines = [
        payout_emoji($o['status']) . ' <b>' . ($o['kind'] === 'refund' ? 'Remboursement' : 'Envoi') . ' de ' . tg_h(fmt_amount($o['amount'], 'F')) . '</b> · ' . tg_h(payout_status_label($o['status'])),
        'Bénéficiaire : ' . tg_h($o['phone']) . ' (' . tg_h($op['name'] ?? $o['operator']) . ')',
        'Référence : <code>' . tg_h($o['reference']) . '</code>',
    ];
    if ($o['payment_id']) {
        $lines[] = 'Paiement remboursé : <code>' . tg_h((string)q_val('SELECT reference FROM payments WHERE id = ?', [$o['payment_id']])) . '</code>';
    }
    if ($o['description']) {
        $lines[] = 'Description : ' . tg_h($o['description']);
    }
    $lines[] = 'Créé : ' . tg_h(fmt_dt($o['created_at'])) . ($o['created_by'] ? ' par ' . tg_h($o['created_by']) : '');
    if ($o['completed_at']) {
        $lines[] = 'Terminé : ' . tg_h(fmt_dt($o['completed_at']));
    }
    if ($o['result_text']) {
        $lines[] = "Réponse de l'opérateur :\n<i>" . tg_h($o['result_text']) . '</i>';
    }
    if ($o['status'] === 'approval') {
        $lines[] = "\n⚠️ Après validation, le téléphone passerelle enverra l'argent automatiquement.";
    } elseif ($o['status'] === 'unknown') {
        $lines[] = "\n⚠️ Résultat incertain : vérifiez sur le téléphone (SMS de l'opérateur, solde) si l'argent est parti.";
    }
    $btns = [];
    if ($o['status'] === 'approval') {
        $btns[] = [['✅ Valider l\'envoi', 'oa:' . $o['reference']], ['🚫 Refuser', 'or:' . $o['reference']]];
    } elseif ($o['status'] === 'queued') {
        $btns[] = [['🚫 Annuler', 'or:' . $o['reference']]];
    } elseif (in_array($o['status'], ['unknown', 'sending'], true)) {
        $btns[] = [['✅ L\'argent est parti', 'oS:' . $o['reference']], ['❌ Rien n\'est parti', 'oF:' . $o['reference']]];
    }
    $btns[] = [['🔄 Actualiser', 'od:' . $o['reference']], ['💸 Envois', 'ol'], ['⬅️ Menu', 'm']];
    return [implode("\n", $lines), $btns];
}

function tg_create_payout(string $args, array $from): array
{
    $tok = preg_split('/\s+/', trim($args));
    $amountTxt = array_shift($tok) ?? '';
    while ($tok && preg_match('/^\d{3}$/', $tok[0]) && preg_match('/^\d{1,3}$/', $amountTxt)) {
        $amountTxt .= array_shift($tok);
    }
    $phone = '';
    while ($tok && preg_match('/^\+?\d+$/', $tok[0]) && strlen(preg_replace('/\D/', '', $phone)) < 8) {
        $phone .= array_shift($tok);
    }
    $opCode = strtolower((string)array_shift($tok));
    $desc = trim(implode(' ', $tok));
    $help = "\n\nExemple : <code>/envoyer 5000 70112233 orange Retrait n°12</code>";
    if ($amountTxt === '' || $phone === '' || $opCode === '') {
        $codes = implode(', ', array_column(array_filter(operators(true, current_scope()), 'operator_can_payout'), 'code'));
        return ['💸 <b>Envoyer de l\'argent</b>' . $help . ($codes ? "\nOpérateurs : " . tg_h($codes) : ''), [[['⬅️ Menu', 'm']]]];
    }
    try {
        $o = payout_create([
            'amount' => preg_replace('/\D/', '', $amountTxt),
            'phone' => $phone,
            'operator' => $opCode,
            'description' => $desc !== '' ? $desc : null,
        ], is_admin_ctx() ? null : ctx()['merchant'], 'telegram:' . trim(($from['first_name'] ?? '') . ' ' . ($from['username'] ?? '')));
    } catch (InvalidArgumentException $e) {
        return ['❌ ' . tg_h(ucfirst((string)preg_replace(['/^amount: /', '/^phone: /', '/^operator: /', '/^\w+: /'], ['Montant : ', 'Numéro : ', 'Opérateur : ', ''], $e->getMessage()))) . $help, [[['⬅️ Menu', 'm']]]];
    }
    return tg_screen_payout($o['reference']);
}
