<?php
declare(strict_types=1);

const PAYMENT_OPEN = ['pending'];

function payment_find(string $reference): ?array
{
    return q_one('SELECT * FROM payments WHERE reference = ?', [$reference]);
}

/**
 * Crée un paiement en attente.
 * @throws InvalidArgumentException
 */
function payment_create(array $in, ?array $merchant): array
{
    $amount = isset($in['amount']) && is_numeric($in['amount']) ? round((float)$in['amount'], 2) : null;
    if ($amount === null || $amount <= 0) {
        throw new InvalidArgumentException('amount: montant invalide.');
    }
    if (fmod($amount, 1.0) != 0.0) {
        throw new InvalidArgumentException('amount: le montant doit être un nombre entier (FCFA).');
    }
    $min = (float)setting('min_amount');
    $max = (float)setting('max_amount');
    if ($amount < $min || $amount > $max) {
        throw new InvalidArgumentException(sprintf('amount: doit être entre %s et %s.', $min, $max));
    }
    // Numéro du payeur : facultatif. S'il est absent, le client le saisit sur la page de paiement.
    $phone = isset($in['payer_phone']) ? trim((string)$in['payer_phone']) : '';
    $key = $phone === '' ? null : phone_key($phone);
    if ($phone !== '' && $key === null) {
        throw new InvalidArgumentException('payer_phone: numéro invalide.');
    }
    foreach (['callback_url', 'return_url'] as $u) {
        if (!empty($in[$u]) && !filter_var($in[$u], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("$u: URL invalide.");
        }
    }
    $operator = null;
    $scope = merchant_scope($merchant);
    if ($scope !== null && !subscription_active($merchant)) {
        throw new SubscriptionRequired('Abonnement inactif : payez votre abonnement mensuel dans votre espace (Abonnement) pour encaisser.');
    }
    if (!empty($in['operator'])) {
        $operator = operator_by_code((string)$in['operator'], $scope);
        if (!$operator || !operator_ready($operator)) {
            $codes = implode(', ', array_column(array_filter(operators(true, $scope), 'operator_ready'), 'code'));
            throw new InvalidArgumentException("operator: opérateur inconnu ou inactif. Valeurs possibles : $codes.");
        }
    } elseif (!array_filter(operators(true, $scope), 'operator_ready')) {
        throw new InvalidArgumentException('Aucun opérateur configuré (Admin > Opérateurs : renseignez au moins un numéro marchand).');
    }
    $metadata = $in['metadata'] ?? null;
    if ($metadata !== null && !is_array($metadata)) {
        throw new InvalidArgumentException('metadata: doit être un objet JSON.');
    }
    $minutes = max(5, (int)setting('payment_expiry_minutes'));

    $data = [
        'reference' => new_reference(),
        'merchant_id' => $merchant['id'] ?? null,
        'operator_id' => $operator['id'] ?? null,
        'external_ref' => isset($in['reference']) ? mb_substr((string)$in['reference'], 0, 190) : null,
        'amount' => $amount,
        'currency' => (string)setting('currency'),
        'payer_phone' => $phone === '' ? null : preg_replace('/[^\d+]/', '', $phone),
        'payer_phone_key' => $key,
        'description' => isset($in['description']) ? mb_substr((string)$in['description'], 0, 255) : null,
        'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        'callback_url' => $in['callback_url'] ?? null,
        'return_url' => $in['return_url'] ?? null,
        'status' => 'pending',
        'created_at' => now(),
        'expires_at' => ts_plus($minutes * 60),
    ];
    $id = db_insert('payments', $data);
    $payment = q_one('SELECT * FROM payments WHERE id = ?', [$id]);

    // Le client a peut-être payé AVANT de créer la demande : on regarde les SMS non rapprochés récents.
    payment_try_late_match($payment);
    return q_one('SELECT * FROM payments WHERE id = ?', [$id]);
}

function payment_try_late_match(array $payment): void
{
    $since = ts_plus(-60 * max(1, (int)setting('late_match_minutes')));
    $params = [$payment['amount'], $since];
    $scopeSql = scope_sql('merchant_id', payment_scope($payment), $params);
    $rows = q_all(
        "SELECT * FROM sms_messages WHERE status = 'unmatched' AND kind = 'incoming' AND amount = ? AND created_at >= ? AND $scopeSql ORDER BY id ASC",
        $params
    );
    foreach ($rows as $sms) {
        if (phone_key($sms['phone']) === $payment['payer_phone_key']) {
            payment_mark_paid($payment, $sms, 'auto-late');
            return;
        }
    }
}

function payment_public(array $p, bool $full = true): array
{
    $amount = (float)$p['amount'];
    $ops = payment_operators($p);
    $primary = $ops[0] ?? operator_default();
    $pp = $primary ? operator_public($primary, $amount) : ['code' => null, 'name' => null, 'merchant_number' => null, 'ussd_code' => null, 'ussd_link' => null, 'ussd_options' => [], 'pay_link' => null];
    $out = [
        'id' => $p['reference'],
        'object' => 'payment',
        'status' => $p['status'],
        'amount' => (float)$p['amount'],
        'currency' => $p['currency'],
        'description' => $p['description'],
        'reference' => $p['external_ref'],
        'payer_phone' => $p['payer_phone'],
        // Opérateur imposé par le marchand (null = le client choisit sur la page de paiement)
        'operator' => !empty($p['operator_id']) ? $pp['code'] : null,
        'operator_name' => $pp['name'],
        'merchant_number' => $pp['merchant_number'],
        'ussd_code' => $pp['ussd_code'],
        'ussd_link' => $pp['ussd_link'],
        'ussd_options' => $pp['ussd_options'],
        'pay_link' => $pp['pay_link'],
        'operators' => array_map(fn($o) => operator_public($o, $amount), $ops),
        'paid_operator' => $p['paid_operator'] ?? null,
        'checkout_url' => abs_url('pay/' . $p['reference']),
        'operator_txn_id' => $p['operator_txn_id'],
        'created_at' => iso($p['created_at']),
        'expires_at' => iso($p['expires_at']),
        'paid_at' => iso($p['paid_at']),
    ];
    if ($full) {
        $out['metadata'] = $p['metadata'] ? json_decode($p['metadata'], true) : null;
        $out['callback_url'] = $p['callback_url'];
        $out['return_url'] = $p['return_url'];
    } else {
        // Page de paiement publique : on ne révèle pas le numéro, seulement s'il est déjà connu (masqué).
        unset($out['payer_phone']);
        $out['payer_phone_set'] = !empty($p['payer_phone_key']);
        $local = $p['payer_phone'] ? substr(preg_replace('/\D+/', '', $p['payer_phone']), -8) : '';
        $out['payer_phone_masked'] = $local !== '' ? substr($local, 0, 2) . '••••' . substr($local, -2) : null;
        $out['return_url'] = $p['status'] === 'paid' ? $p['return_url'] : null;
    }
    return $out;
}

function payment_mark_paid(array $payment, ?array $sms, string $how = 'auto'): bool
{
    $data = [
        'status' => 'paid',
        'paid_at' => now(),
        'sms_id' => $sms['id'] ?? null,
        'operator_txn_id' => $sms['txn_id'] ?? null,
        'paid_amount' => $sms['amount'] ?? $payment['amount'],
        'paid_operator' => $sms['operator'] ?? null,
    ];
    // Verrou optimiste : un seul SMS peut valider un paiement.
    $n = db_update('payments', $data, "id = :id AND status IN ('pending','expired')", ['id' => $payment['id']]);
    if ($n !== 1) {
        return false;
    }
    if ($sms) {
        db_update('sms_messages', [
            'status' => 'matched',
            'payment_id' => $payment['id'],
            'note' => $how === 'manual' ? 'Validé manuellement' : ($how === 'auto-late' ? 'Rapproché à la création' : null),
        ], 'id = :id', ['id' => $sms['id']]);
    }
    log_line("payment {$payment['reference']} paid ($how)");
    webhook_queue($payment['id'], 'payment.succeeded');
    subscription_on_paid($payment);
    if ($how !== 'manual') {
        $m = $payment['merchant_id'] ? q_val('SELECT name FROM merchants WHERE id = ?', [$payment['merchant_id']]) : null;
        push_notify(
            '💰 Paiement reçu : ' . fmt_amount($payment['amount'], 'F'),
            'De ' . ($payment['payer_phone'] ?: 'client') . ($m ? ' · ' . $m : '') . ' · ' . $payment['reference'],
            'payments/' . $payment['reference'],
            'pay-' . $payment['reference'],
            payment_scope($payment)
        );
    }
    return true;
}

function payment_set_status(array $payment, string $status): bool
{
    $n = db_update('payments', ['status' => $status], "id = :id AND status = 'pending'", ['id' => $payment['id']]);
    if ($n === 1) {
        webhook_queue($payment['id'], 'payment.' . $status);
        return true;
    }
    return false;
}

function payments_expire(): int
{
    $rows = q_all("SELECT * FROM payments WHERE status = 'pending' AND expires_at < ?", [now()]);
    $count = 0;
    foreach ($rows as $p) {
        if (payment_set_status($p, 'expired')) {
            $count++;
        }
    }
    return $count;
}

/** Cherche le paiement en attente correspondant à un SMS reçu. */
function payment_match_for(float $amount, ?string $phone, ?int $scope = null, bool $amountOnly = false): ?array
{
    $key = phone_key($phone);
    if ($key !== null) {
        $params = [$amount, $key, now()];
        $sc = payments_scope_sql($scope, $params);
        return q_one(
            "SELECT * FROM payments WHERE status = 'pending' AND amount = ? AND payer_phone_key = ? AND expires_at >= ? AND $sc ORDER BY id ASC LIMIT 1",
            $params
        );
    }
    if ($amountOnly || setting('match_amount_only') === '1') {
        $params = [$amount, now()];
        $sc = payments_scope_sql($scope, $params);
        $rows = q_all(
            "SELECT * FROM payments WHERE status = 'pending' AND amount = ? AND expires_at >= ? AND $sc ORDER BY id ASC LIMIT 2",
            $params
        );
        if (count($rows) === 1) {
            return $rows[0];
        }
    }
    return null;
}

/**
 * Enregistre un SMS reçu par un téléphone et tente de le rapprocher d'un paiement.
 * Retourne ['id' => ..., 'status' => ..., 'payment' => reference|null]
 */
function sms_ingest(?array $device, string $sender, string $body, ?int $receivedAtMs = null, string $source = 'sms', $scope = 'device'): array
{
    // Périmètre : marchand propriétaire du téléphone (null = passerelle de la plateforme).
    if ($scope === 'device') {
        $scope = $device && isset($device['merchant_id']) && $device['merchant_id'] !== null ? (int)$device['merchant_id'] : null;
    }
    $sender = mb_substr(trim($sender), 0, 60);
    $body = trim($body);
    $receivedAt = $receivedAtMs ? gmdate('Y-m-d H:i:s', intdiv($receivedAtMs, 1000)) : now();
    $fingerprint = hash('sha256', ($device['id'] ?? 0) . '|' . $sender . '|' . $body . '|' . ($receivedAtMs ?? $receivedAt));

    $existing = q_one('SELECT id, status, payment_id FROM sms_messages WHERE fingerprint = ?', [$fingerprint]);
    if ($existing) {
        return ['id' => (int)$existing['id'], 'status' => 'already_received', 'payment' => null];
    }

    $parsed = parse_sms($sender, $body);
    $row = [
        'device_id' => $device['id'] ?? null,
        'merchant_id' => $scope,
        'sender' => $sender,
        'body' => $body,
        'received_at' => $receivedAt,
        'fingerprint' => $fingerprint,
        'rule_name' => $parsed['rule'] ?? null,
        'kind' => $parsed['kind'] ?? null,
        'amount' => $parsed['amount'] ?? null,
        'phone' => $parsed['phone'] ?? null,
        'txn_id' => $parsed['txn'] ?? null,
        'balance' => $parsed['balance'] ?? null,
        'operator' => $parsed['operator'] ?? (operator_for_sender($sender, $scope)['code'] ?? null),
        'source' => $source === 'notification' ? 'notification' : 'sms',
        'status' => 'ignored',
        'note' => null,
        'created_at' => now(),
    ];

    if ($parsed === null) {
        $row['note'] = 'Aucune règle ne correspond';
        $id = db_insert('sms_messages', $row);
        return ['id' => $id, 'status' => 'ignored', 'payment' => null];
    }

    if ($parsed['kind'] !== 'incoming') {
        $row['status'] = 'info';
        $id = db_insert('sms_messages', $row);
        if ($device && $parsed['balance'] !== null) {
            device_set_balance((int)$device['id'], $row['operator'], (float)$parsed['balance']);
        }
        return ['id' => $id, 'status' => 'info', 'payment' => null];
    }

    // Anti-doublon : même ID de transaction opérateur déjà traité.
    if ($parsed['txn']) {
        $dp = [$parsed['txn']];
        $dsc = scope_sql('merchant_id', $scope, $dp);
        $dup = q_one("SELECT id FROM sms_messages WHERE txn_id = ? AND kind = 'incoming' AND status IN ('matched','unmatched','review') AND $dsc", $dp);
        if ($dup) {
            $row['status'] = 'duplicate';
            $row['note'] = 'ID de transaction déjà reçu (SMS #' . $dup['id'] . ')';
            $id = db_insert('sms_messages', $row);
            return ['id' => $id, 'status' => 'duplicate', 'payment' => null];
        }
    }

    // Anti-fraude : le nouveau solde doit être égal à l'ancien + le montant reçu.
    $suspect = null;
    $known = $device ? device_balance($device, $row['operator']) : null;
    if ($device && setting('balance_check') === '1' && $parsed['balance'] !== null && $known !== null) {
        $expected = round($known + (float)$parsed['amount'], 2);
        if (abs($expected - (float)$parsed['balance']) > 1.0) {
            $suspect = sprintf('Solde incohérent : attendu %s, SMS annonce %s', fmt_amount($expected), fmt_amount($parsed['balance']));
        }
    }

    // Une notification d'application (ex. Wave) est fiable mais ne donne pas toujours le numéro :
    // on accepte alors le montant seul s'il n'y a qu'un seul paiement en attente de ce montant.
    $payment = payment_match_for((float)$parsed['amount'], $parsed['phone'], $scope, $source === 'notification');

    if ($suspect !== null) {
        $row['status'] = 'review';
        $row['note'] = $suspect;
        $row['payment_id'] = $payment['id'] ?? null; // candidat, pas encore validé
        $id = db_insert('sms_messages', $row);
        log_line("sms #$id en revue: $suspect");
        push_notify('🔎 SMS à vérifier : ' . fmt_amount($parsed['amount'], 'F'), $suspect, 'sms?status=review', 'sms-' . $id, $scope);
        return ['id' => $id, 'status' => 'review', 'payment' => null];
    }

    if ($device && $parsed['balance'] !== null) {
        device_set_balance((int)$device['id'], $row['operator'], (float)$parsed['balance']);
    }

    $row['status'] = 'unmatched';
    $id = db_insert('sms_messages', $row);
    $sms = q_one('SELECT * FROM sms_messages WHERE id = ?', [$id]);

    if ($payment && payment_mark_paid($payment, $sms)) {
        return ['id' => $id, 'status' => 'matched', 'payment' => $payment['reference']];
    }
    push_notify(
        '⚠️ Argent reçu sans paiement : ' . fmt_amount($parsed['amount'], 'F'),
        'De ' . ($parsed['phone'] ?: 'numéro inconnu') . '. Rapprochez-le dans votre espace.',
        'sms?status=unmatched',
        'sms-' . $id,
        $scope
    );
    return ['id' => $id, 'status' => 'unmatched', 'payment' => null];
}

/**
 * Le client indique, sur la page de paiement, l'opérateur choisi et le numéro avec lequel il paie.
 * Possible tant que le paiement est en attente.
 */
function payment_set_payer(array $p, string $phone, ?string $operatorCode): array
{
    if ($p['status'] !== 'pending') {
        throw new InvalidArgumentException('Ce paiement n\'est plus en attente.');
    }
    $key = phone_key($phone);
    if ($key === null) {
        throw new InvalidArgumentException('Numéro de téléphone invalide.');
    }
    $data = ['payer_phone' => preg_replace('/[^\d+]/', '', $phone), 'payer_phone_key' => $key];
    if ($operatorCode !== null && $operatorCode !== '' && empty($p['operator_id'])) {
        $op = operator_by_code($operatorCode, payment_scope($p));
        if (!$op || !operator_ready($op)) {
            throw new InvalidArgumentException('Moyen de paiement indisponible.');
        }
        // Mémorisé à titre indicatif : le paiement reste reconnu quel que soit l'opérateur utilisé.
    }
    db_update('payments', $data, "id = :id AND status = 'pending'", ['id' => $p['id']]);
    $p = payment_find($p['reference']);
    payment_try_late_match($p); // le client a peut-être déjà payé
    return payment_find($p['reference']);
}

/**
 * Actions sur un SMS reçu (espace web et bot Telegram) : « match » (rapprocher d'un paiement),
 * « approve » (SMS à vérifier : l'argent est bien arrivé), « ignore ».
 * @return array{0: bool, 1: string} [succès, message à afficher]
 */
function sms_action(array $sms, string $action, string $reference = ''): array
{
    $smsScope = $sms['merchant_id'] !== null ? (int)$sms['merchant_id'] : null;
    if ($action === 'match') {
        $pay = payment_find(trim($reference));
        if ($pay && payment_scope($pay) !== $smsScope) {
            $pay = null; // le SMS et le paiement doivent appartenir au même marchand
        }
        if (!$pay) {
            return [false, 'Référence de paiement introuvable.'];
        }
        if (!in_array($pay['status'], ['pending', 'expired'], true)) {
            return [false, 'Ce paiement est déjà ' . status_label($pay['status']) . '.'];
        }
        if (in_array($sms['status'], ['matched', 'ignored', 'duplicate'], true)) {
            return [false, 'Ce SMS est déjà ' . status_label($sms['status']) . '.'];
        }
        payment_mark_paid($pay, $sms, 'manual');
        if ($sms['balance'] !== null && $sms['device_id']) {
            device_set_balance((int)$sms['device_id'], $sms['operator'], (float)$sms['balance']);
        }
        return [true, 'SMS rapproché du paiement ' . $pay['reference'] . '.'];
    }
    if ($action === 'approve') {
        if ($sms['status'] !== 'review') {
            return [false, 'Ce SMS n\'est pas « à vérifier ».'];
        }
        // L'argent est bien arrivé : on resynchronise le solde puis on rapproche.
        if ($sms['balance'] !== null && $sms['device_id']) {
            device_set_balance((int)$sms['device_id'], $sms['operator'], (float)$sms['balance']);
        }
        db_update('sms_messages', ['status' => 'unmatched', 'note' => 'Vérifié par l\'admin'], 'id = :id', ['id' => $sms['id']]);
        $sms = q_one('SELECT * FROM sms_messages WHERE id = ?', [$sms['id']]);
        $pay = $sms['payment_id'] ? q_one('SELECT * FROM payments WHERE id = ?', [$sms['payment_id']]) : payment_match_for((float)$sms['amount'], $sms['phone'], $smsScope);
        if ($pay && payment_mark_paid($pay, $sms, 'manual')) {
            return [true, 'SMS validé et paiement ' . $pay['reference'] . ' confirmé.'];
        }
        return [true, 'SMS validé. Aucun paiement en attente correspondant : rapprochez-le manuellement si besoin.'];
    }
    if ($action === 'ignore') {
        if ($sms['status'] === 'matched') {
            return [false, 'Ce SMS a déjà validé un paiement.'];
        }
        db_update('sms_messages', ['status' => 'ignored', 'note' => 'Ignoré par l\'admin'], 'id = :id', ['id' => $sms['id']]);
        return [true, 'SMS ignoré.'];
    }
    return [false, 'Action inconnue.'];
}
