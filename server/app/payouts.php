<?php
declare(strict_types=1);

/* ============================================================
 *  ENVOIS D'ARGENT : remboursements et paiements sortants (payouts)
 *
 *  Le téléphone passerelle compose lui-même le code USSD d'envoi
 *  (ex. *144*2*1*{number}*{amount}#) puis saisit le code secret,
 *  qui reste UNIQUEMENT dans le téléphone (jamais sur le serveur).
 *
 *  Cycle : approval (à valider) -> queued (en file) -> sending (le téléphone compose)
 *          -> success | failed | unknown (à vérifier) ; ou cancelled (refusé).
 *  Sécurité : plafond par envoi, plafond journalier, validation manuelle au-dessus
 *  d'un montant, aucun renvoi automatique quand le résultat est incertain.
 * ============================================================ */

const PAYOUT_OPEN = ['approval', 'queued', 'sending'];

function payout_status_label(string $s): string
{
    return [
        'approval' => 'À valider', 'queued' => 'En file', 'sending' => 'Envoi en cours', 'success' => 'Envoyé',
        'failed' => 'Échec', 'unknown' => 'À vérifier', 'cancelled' => 'Refusé',
    ][$s] ?? $s;
}

function payout_emoji(string $s): string
{
    return ['approval' => '🕒', 'queued' => '📤', 'sending' => '📲', 'success' => '✅', 'failed' => '❌', 'unknown' => '⚠️', 'cancelled' => '🚫'][$s] ?? '•';
}

function payouts_enabled(): bool
{
    return setting('payouts_enabled', '1') === '1';
}

function payout_find(string $ref): ?array
{
    return q_one('SELECT * FROM payouts WHERE reference = ?', [$ref]);
}

/** Opérateur capable d'envoyer (code USSD d'envoi renseigné). */
function operator_can_payout(?array $op): bool
{
    return $op && (int)$op['active'] === 1 && trim((string)($op['payout_ussd'] ?? '')) !== '';
}

/** Code USSD à composer, sans le code secret (le téléphone le saisit lui-même). */
function payout_dial_string(array $op, array $payout): string
{
    return str_replace(
        ['{number}', '{amount}'],
        [(string)$payout['phone'], (string)(int)round((float)$payout['amount'])],
        trim((string)$op['payout_ussd'])
    );
}

/** Total envoyé (ou en cours) aujourd'hui dans un périmètre. */
function payouts_today_total(?int $scope): float
{
    $tz = new DateTimeZone((string)setting('timezone', 'Africa/Ouagadougou'));
    $start = (new DateTime('today', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $p = [$start];
    return (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status NOT IN ('failed','cancelled') AND created_at >= ? AND " . scope_sql('scope_id', $scope, $p), $p);
}

/**
 * Crée un envoi d'argent.
 * $in : amount, phone, operator, [reference, description, callback_url, metadata]
 * @throws InvalidArgumentException
 */
function payout_create(array $in, ?array $merchant, string $by = 'api', ?array $payment = null): array
{
    if (!payouts_enabled()) {
        throw new InvalidArgumentException('Les envois d\'argent sont désactivés sur la plateforme.');
    }
    $scope = $payment ? payment_scope($payment) : merchant_scope($merchant);
    if ($merchant && merchant_scope($merchant) !== null && !subscription_active($merchant)) {
        throw new SubscriptionRequired('Abonnement inactif : payez votre abonnement mensuel pour utiliser les envois.');
    }
    $amount = isset($in['amount']) && is_numeric($in['amount']) ? round((float)$in['amount']) : 0;
    if ($amount <= 0) {
        throw new InvalidArgumentException('amount: montant invalide.');
    }
    $max = (float)setting('payout_max', '200000');
    if ($max > 0 && $amount > $max) {
        throw new InvalidArgumentException('amount: maximum ' . fmt_amount($max, 'F') . ' par envoi.');
    }
    $phone = phone_key((string)($in['phone'] ?? ''));
    if ($phone === null) {
        throw new InvalidArgumentException('phone: numéro du bénéficiaire invalide (8 chiffres).');
    }
    $op = operator_by_code((string)($in['operator'] ?? ''), $scope);
    if (!$op) {
        $codes = implode(', ', array_column(array_filter(operators(true, $scope), 'operator_can_payout'), 'code'));
        throw new InvalidArgumentException('operator: opérateur inconnu.' . ($codes ? " Valeurs possibles : $codes." : ''));
    }
    if (!operator_can_payout($op)) {
        throw new InvalidArgumentException('operator: les envois par ' . $op['name'] . ' ne sont pas encore possibles (code USSD d\'envoi non configuré).');
    }
    $daily = (float)setting('payout_daily_limit', '500000');
    if ($daily > 0 && payouts_today_total($scope) + $amount > $daily) {
        throw new InvalidArgumentException('amount: plafond journalier des envois atteint (' . fmt_amount($daily, 'F') . ').');
    }
    foreach (['callback_url'] as $u) {
        if (!empty($in[$u]) && !filter_var($in[$u], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("$u: URL invalide.");
        }
    }
    $metadata = $in['metadata'] ?? null;
    if ($metadata !== null && !is_array($metadata)) {
        throw new InvalidArgumentException('metadata: doit être un objet JSON.');
    }
    // Validation manuelle au-dessus du seuil (0 = toujours valider à la main).
    $autoMax = (float)setting('payout_auto_max', '0');
    $needApproval = $autoMax <= 0 || $amount > $autoMax;

    $id = db_insert('payouts', [
        'reference' => 'OUT-' . substr(new_reference(), 4),
        'merchant_id' => $merchant['id'] ?? ($payment['merchant_id'] ?? null),
        'scope_id' => $scope,
        'payment_id' => $payment['id'] ?? null,
        'kind' => $payment ? 'refund' : 'payout',
        'operator' => $op['code'],
        'phone' => $phone,
        'amount' => $amount,
        'external_ref' => isset($in['reference']) ? mb_substr((string)$in['reference'], 0, 190) : null,
        'description' => isset($in['description']) ? mb_substr((string)$in['description'], 0, 255) : null,
        'callback_url' => $in['callback_url'] ?? null,
        'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        'status' => $needApproval ? 'approval' : 'queued',
        'created_by' => mb_substr($by, 0, 60),
        'created_at' => now(),
        'approved_at' => $needApproval ? null : now(),
    ]);
    $o = q_one('SELECT * FROM payouts WHERE id = ?', [$id]);
    log_line("payout {$o['reference']} créé ({$o['status']}) par $by");
    $what = ($o['kind'] === 'refund' ? 'Remboursement' : 'Envoi') . ' de ' . fmt_amount($amount, 'F') . ' vers ' . $phone . ' (' . $op['name'] . ')';
    if ($needApproval) {
        push_notify('🕒 ' . $what . ' à valider', ($o['description'] ? $o['description'] . ' · ' : '') . $o['reference'], 'payouts', 'out-' . $o['reference'], $scope);
    }
    return $o;
}

/** Remboursement d'un paiement reçu, vers le numéro et l'opérateur qui ont payé. */
function payout_refund(array $payment, ?float $amount, ?array $merchant, string $by = 'api', string $reason = ''): array
{
    if ($payment['status'] !== 'paid') {
        throw new InvalidArgumentException('Seul un paiement payé peut être remboursé.');
    }
    $paid = (float)($payment['paid_amount'] ?: $payment['amount']);
    $already = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE payment_id = ? AND status NOT IN ('failed','cancelled')", [$payment['id']]);
    $left = $paid - $already;
    if ($left <= 0) {
        throw new InvalidArgumentException('Ce paiement a déjà été entièrement remboursé.');
    }
    $amount = $amount === null || $amount <= 0 ? $left : $amount;
    if ($amount > $left) {
        throw new InvalidArgumentException('amount: au maximum ' . fmt_amount($left, 'F') . ' peuvent encore être remboursés.');
    }
    $sms = $payment['sms_id'] ? q_one('SELECT phone, operator FROM sms_messages WHERE id = ?', [$payment['sms_id']]) : null;
    $opCode = $payment['paid_operator'] ?: ($sms['operator'] ?? null);
    if (!$opCode && !empty($payment['operator_id'])) {
        $opCode = operator_by_id($payment['operator_id'])['code'] ?? null;
    }
    $phone = $payment['payer_phone'] ?: ($sms['phone'] ?? null);
    if (!$opCode || !phone_key((string)$phone)) {
        throw new InvalidArgumentException('Impossible de rembourser automatiquement : numéro ou opérateur du client inconnu.');
    }
    return payout_create([
        'amount' => $amount,
        'phone' => $phone,
        'operator' => $opCode,
        'reference' => $payment['external_ref'],
        'description' => 'Remboursement ' . $payment['reference'] . ($reason !== '' ? ' : ' . $reason : ''),
    ], $merchant, $by, $payment);
}

function payout_public(array $o): array
{
    $op = operator_by_code((string)$o['operator'], $o['scope_id'] !== null ? (int)$o['scope_id'] : null);
    return [
        'id' => $o['reference'],
        'object' => $o['kind'] === 'refund' ? 'refund' : 'payout',
        'status' => $o['status'],
        'amount' => (float)$o['amount'],
        'currency' => (string)setting('currency'),
        'phone' => $o['phone'],
        'operator' => $o['operator'],
        'operator_name' => $op['name'] ?? $o['operator'],
        'payment' => $o['payment_id'] ? q_val('SELECT reference FROM payments WHERE id = ?', [$o['payment_id']]) : null,
        'reference' => $o['external_ref'],
        'description' => $o['description'],
        'operator_message' => $o['result_text'],
        'operator_txn_id' => $o['operator_txn_id'],
        'metadata' => $o['metadata'] ? json_decode($o['metadata'], true) : null,
        'created_at' => iso($o['created_at']),
        'completed_at' => iso($o['completed_at']),
    ];
}

/** Webhook vers le marchand : payout.succeeded / payout.failed / payout.cancelled / payout.unknown */
function payout_webhook(array $o, string $event): void
{
    $merchant = $o['merchant_id'] ? q_one('SELECT * FROM merchants WHERE id = ?', [$o['merchant_id']]) : null;
    $url = $o['callback_url'] ?: ($merchant['webhook_url'] ?? null);
    if (!$url) {
        return;
    }
    $payload = json_encode([
        'id' => 'evt_' . bin2hex(random_bytes(8)),
        'type' => $event,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'data' => payout_public($o),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $id = db_insert('webhook_deliveries', [
        'payment_id' => (int)($o['payment_id'] ?? 0),
        'merchant_id' => $o['merchant_id'],
        'url' => $url,
        'event' => $event,
        'payload' => $payload,
        'attempts' => 0,
        'status' => 'pending',
        'next_attempt_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    webhook_deliver($id);
}

function payout_approve(array $o, string $by): bool
{
    $n = db_update('payouts', ['status' => 'queued', 'approved_at' => now(), 'approved_by' => mb_substr($by, 0, 60)], "id = :id AND status = 'approval'", ['id' => $o['id']]);
    if ($n === 1) {
        log_line("payout {$o['reference']} validé par $by");
    }
    return $n === 1;
}

function payout_cancel(array $o, string $by): bool
{
    $n = db_update('payouts', ['status' => 'cancelled', 'completed_at' => now(), 'approved_by' => mb_substr($by, 0, 60)], "id = :id AND status IN ('approval','queued')", ['id' => $o['id']]);
    if ($n === 1) {
        payout_webhook(q_one('SELECT * FROM payouts WHERE id = ?', [$o['id']]), 'payout.cancelled');
    }
    return $n === 1;
}

/** Le téléphone prend le prochain envoi validé de son périmètre, pour un opérateur qu'il sait utiliser. */
function payout_claim(array $device, array $operatorCodes): ?array
{
    $operatorCodes = array_values(array_filter(array_map('strval', $operatorCodes)));
    if (!$operatorCodes || !payouts_enabled()) {
        return null;
    }
    $scope = $device['merchant_id'] !== null ? (int)$device['merchant_id'] : null;
    $p = [];
    $in = implode(',', array_fill(0, count($operatorCodes), '?'));
    $sql = "SELECT * FROM payouts WHERE status = 'queued' AND " . scope_sql('scope_id', $scope, $p) . " AND operator IN ($in) ORDER BY id LIMIT 1";
    $o = q_one($sql, array_merge($p, $operatorCodes));
    if (!$o) {
        return null;
    }
    // Un seul téléphone peut prendre un envoi (verrou optimiste).
    $n = db_update('payouts', ['status' => 'sending', 'device_id' => $device['id'], 'sent_at' => now(), 'attempts' => (int)$o['attempts'] + 1], "id = :id AND status = 'queued'", ['id' => $o['id']]);
    if ($n !== 1) {
        return null;
    }
    $op = operator_by_code($o['operator'], $scope);
    return [
        'id' => $o['reference'],
        'operator' => $o['operator'],
        'operator_name' => $op['name'] ?? $o['operator'],
        'phone' => $o['phone'],
        'amount' => (int)$o['amount'],
        'ussd' => payout_dial_string($op, $o),
        'success_pattern' => (string)($op['payout_success'] ?? ''),
    ];
}

/** Classement du message final de l'opérateur. */
function payout_classify(string $text, ?array $op): string
{
    $t = mb_strtolower($text);
    $fail = '/(échou|echou|échec|echec|insuffisant|incorrect|invalide|erron|refus|annul|impossible|bloqu|expir|not allowed|failed|error|erreur|plafond|limite)/u';
    $ok = '/(succ[eè]s|r[ée]ussi|effectu[ée]|transf[ée]r[ée]|envoy[ée]|a [ée]t[ée] (?:cr[ée]dit|envoy|transf)|confirm[ée])/u';
    $custom = trim((string)($op['payout_success'] ?? ''));
    if ($custom !== '' && rx_valid($custom)) {
        $ok = '/' . str_replace('/', '\/', $custom) . '/iu';
    }
    if (preg_match($fail, $t)) {
        return 'failed';
    }
    if (preg_match($ok, $t)) {
        return 'success';
    }
    return 'unknown';
}

/**
 * Résultat envoyé par le téléphone.
 * $status : 'success' | 'failed' (rien n'a été envoyé, ex. code secret non saisi) | 'unknown' (à vérifier)
 */
function payout_report(array $o, array $device, string $status, string $message, bool $pinSent): array
{
    if ($o['status'] !== 'sending' || (int)$o['device_id'] !== (int)$device['id']) {
        return ['ok' => false, 'status' => $o['status']];
    }
    $op = operator_by_code($o['operator'], $o['scope_id'] !== null ? (int)$o['scope_id'] : null);
    if ($status === 'done') {
        $status = payout_classify($message, $op);
    }
    if (!in_array($status, ['success', 'failed', 'unknown'], true)) {
        $status = 'unknown';
    }
    // Sans code secret, aucun argent ne peut partir : un résultat incertain est un échec (réessayable).
    if ($status === 'unknown' && !$pinSent) {
        $status = 'failed';
    }
    // Code secret envoyé sans confirmation claire : on ne sait pas si l'argent est parti -> à vérifier.
    if ($status === 'failed' && $pinSent && payout_classify($message, $op) !== 'failed') {
        $status = 'unknown';
    }
    $txn = null;
    if (preg_match('/(?:ID|Ref|Réf|Trans(?:action)?)\s*[:.]?\s*([A-Z0-9][A-Z0-9.\-]{5,})/iu', $message, $m)) {
        $txn = $m[1];
    }
    db_update('payouts', [
        'status' => $status,
        'result_text' => mb_substr($message, 0, 500),
        'operator_txn_id' => $txn,
        'completed_at' => now(),
    ], 'id = :id', ['id' => $o['id']]);
    $o = q_one('SELECT * FROM payouts WHERE id = ?', [$o['id']]);
    payout_after_result($o);
    return ['ok' => true, 'status' => $status];
}

/** Notifications et webhook après un résultat (téléphone ou décision manuelle). */
function payout_after_result(array $o): void
{
    $op = operator_by_code($o['operator'], $o['scope_id'] !== null ? (int)$o['scope_id'] : null);
    $what = ($o['kind'] === 'refund' ? 'Remboursement' : 'Envoi') . ' de ' . fmt_amount($o['amount'], 'F') . ' vers ' . $o['phone'] . ' (' . ($op['name'] ?? $o['operator']) . ')';
    $scope = $o['scope_id'] !== null ? (int)$o['scope_id'] : null;
    $tail = ($o['result_text'] ? "\n« " . mb_substr($o['result_text'], 0, 160) . ' »' : '') . "\n" . $o['reference'];
    if ($o['status'] === 'success') {
        push_notify('✅ ' . $what . ' réussi', ltrim($tail), 'payouts', 'out-' . $o['reference'], $scope);
        payout_webhook($o, 'payout.succeeded');
    } elseif ($o['status'] === 'failed') {
        push_notify('❌ ' . $what . ' échoué', ltrim($tail), 'payouts', 'out-' . $o['reference'], $scope);
        payout_webhook($o, 'payout.failed');
    } elseif ($o['status'] === 'unknown') {
        push_notify('⚠️ ' . $what . ' à vérifier', 'Vérifiez sur le téléphone si l\'argent est parti, puis indiquez le résultat.' . $tail, 'payouts', 'out-' . $o['reference'], $scope);
    }
}

/** Décision manuelle pour un envoi « à vérifier » (ou bloqué en cours). */
function payout_resolve(array $o, string $status, string $by): bool
{
    if (!in_array($status, ['success', 'failed'], true)) {
        return false;
    }
    $n = db_update('payouts', [
        'status' => $status,
        'completed_at' => now(),
        'result_text' => trim(($o['result_text'] ?? '') . ' [' . ($status === 'success' ? 'confirmé' : 'déclaré échoué') . ' par ' . $by . ']'),
    ], "id = :id AND status IN ('unknown','sending')", ['id' => $o['id']]);
    if ($n === 1) {
        payout_after_result(q_one('SELECT * FROM payouts WHERE id = ?', [$o['id']]));
    }
    return $n === 1;
}

/** Remet en file un envoi échoué (rien n'est parti). */
function payout_retry(array $o): bool
{
    return db_update('payouts', ['status' => 'queued', 'device_id' => null, 'result_text' => null, 'completed_at' => null], "id = :id AND status = 'failed'", ['id' => $o['id']]) === 1;
}

/** Tâche périodique : un envoi « en cours » depuis plus de 5 min devient « à vérifier » (jamais renvoyé tout seul). */
function payouts_watchdog(): int
{
    $rows = q_all("SELECT * FROM payouts WHERE status = 'sending' AND sent_at < ?", [ts_plus(-300)]);
    foreach ($rows as $o) {
        db_update('payouts', ['status' => 'unknown', 'result_text' => 'Aucune réponse du téléphone après 5 minutes.', 'completed_at' => now()], "id = :id AND status = 'sending'", ['id' => $o['id']]);
        payout_after_result(q_one('SELECT * FROM payouts WHERE id = ?', [$o['id']]));
    }
    return count($rows);
}

/** Nombre d'envois validés qui attendent un téléphone de ce périmètre. */
function payouts_queued_count(?int $scope): int
{
    $p = [];
    return (int)q_val("SELECT COUNT(*) FROM payouts WHERE status = 'queued' AND " . scope_sql('scope_id', $scope, $p), $p);
}

/** Un SMS d'argent sorti (même montant, même numéro, même opérateur, < 30 min) confirme un envoi incertain. */
function payout_confirm_by_sms(array $sms, ?array $device): void
{
    try {
        $p = [(float)$sms['amount'], ts_plus(-1800)];
        $where = "status IN ('sending','unknown') AND amount = ? AND created_at >= ?";
        if ($device) {
            $where .= ' AND (device_id = ? OR device_id IS NULL)';
            $p[] = $device['id'];
        }
        if (!empty($sms['operator'])) {
            $where .= ' AND operator = ?';
            $p[] = $sms['operator'];
        }
        $key = phone_key((string)$sms['phone']);
        foreach (q_all("SELECT * FROM payouts WHERE $where ORDER BY id", $p) as $o) {
            if ($key !== null && $key !== $o['phone']) {
                continue;
            }
            $n = db_update('payouts', [
                'status' => 'success',
                'completed_at' => now(),
                'operator_txn_id' => $sms['txn_id'] ?: null,
                'result_text' => mb_substr(trim(($o['result_text'] ? $o['result_text'] . ' | ' : '') . 'SMS : ' . $sms['body']), 0, 500),
            ], "id = :id AND status IN ('sending','unknown')", ['id' => $o['id']]);
            if ($n === 1) {
                payout_after_result(q_one('SELECT * FROM payouts WHERE id = ?', [$o['id']]));
                return;
            }
        }
    } catch (Throwable $e) {
        log_line('payout sms: ' . $e->getMessage());
    }
}
