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
    $phone = isset($in['payer_phone']) ? trim((string)$in['payer_phone']) : '';
    $key = phone_key($phone);
    if ($key === null) {
        throw new InvalidArgumentException('payer_phone: numéro du payeur obligatoire (celui qui va payer).');
    }
    foreach (['callback_url', 'return_url'] as $u) {
        if (!empty($in[$u]) && !filter_var($in[$u], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("$u: URL invalide.");
        }
    }
    $operator = null;
    if (!empty($in['operator'])) {
        $operator = operator_by_code((string)$in['operator']);
        if (!$operator || !operator_ready($operator)) {
            $codes = implode(', ', array_column(array_filter(operators(true), 'operator_ready'), 'code'));
            throw new InvalidArgumentException("operator: opérateur inconnu ou inactif. Valeurs possibles : $codes.");
        }
    } elseif (!array_filter(operators(true), 'operator_ready')) {
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
        'payer_phone' => preg_replace('/[^\d+]/', '', $phone),
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
    $rows = q_all(
        "SELECT * FROM sms_messages WHERE status = 'unmatched' AND kind = 'incoming' AND amount = ? AND created_at >= ? ORDER BY id ASC",
        [$payment['amount'], $since]
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
        unset($out['payer_phone']);
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
    if ($how !== 'manual') {
        $m = $payment['merchant_id'] ? q_val('SELECT name FROM merchants WHERE id = ?', [$payment['merchant_id']]) : null;
        push_notify(
            '💰 Paiement reçu : ' . fmt_amount($payment['amount'], 'F'),
            'De ' . $payment['payer_phone'] . ($m ? ' · ' . $m : '') . ' · ' . $payment['reference'],
            'admin/payments/' . $payment['reference'],
            'pay-' . $payment['reference']
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
function payment_match_for(float $amount, ?string $phone): ?array
{
    $key = phone_key($phone);
    if ($key !== null) {
        return q_one(
            "SELECT * FROM payments WHERE status = 'pending' AND amount = ? AND payer_phone_key = ? AND expires_at >= ? ORDER BY id ASC LIMIT 1",
            [$amount, $key, now()]
        );
    }
    if (setting('match_amount_only') === '1') {
        $rows = q_all(
            "SELECT * FROM payments WHERE status = 'pending' AND amount = ? AND expires_at >= ? ORDER BY id ASC LIMIT 2",
            [$amount, now()]
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
function sms_ingest(?array $device, string $sender, string $body, ?int $receivedAtMs = null): array
{
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
        'operator' => $parsed['operator'] ?? (operator_for_sender($sender)['code'] ?? null),
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
        $dup = q_one("SELECT id FROM sms_messages WHERE txn_id = ? AND kind = 'incoming' AND status IN ('matched','unmatched','review')", [$parsed['txn']]);
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

    $payment = payment_match_for((float)$parsed['amount'], $parsed['phone']);

    if ($suspect !== null) {
        $row['status'] = 'review';
        $row['note'] = $suspect;
        $row['payment_id'] = $payment['id'] ?? null; // candidat, pas encore validé
        $id = db_insert('sms_messages', $row);
        log_line("sms #$id en revue: $suspect");
        push_notify('🔎 SMS à vérifier : ' . fmt_amount($parsed['amount'], 'F'), $suspect, 'admin/sms?status=review', 'sms-' . $id);
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
        'De ' . ($parsed['phone'] ?: 'numéro inconnu') . '. Rapprochez-le dans l\'admin.',
        'admin/sms?status=unmatched',
        'sms-' . $id
    );
    return ['id' => $id, 'status' => 'unmatched', 'payment' => null];
}
