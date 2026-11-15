<?php
declare(strict_types=1);

/**
 * Abonnement mensuel des marchands inscrits (espace marchand).
 * Le marchand paie sur les numéros de la PLATEFORME (opérateurs de l'administrateur) ;
 * quand le SMS est reçu sur le téléphone de la plateforme, son abonnement est prolongé.
 */
class SubscriptionRequired extends InvalidArgumentException
{
}

function subscription_enabled(): bool
{
    return setting('subscription_enabled') === '1';
}

function subscription_price(): float
{
    return max(1, (float)setting('subscription_price'));
}

function subscription_days(): int
{
    return max(1, (int)setting('subscription_days'));
}

/** Un marchand autonome peut-il encaisser ? (les marchands de la plateforme ne paient pas d'abonnement) */
function subscription_active(?array $m): bool
{
    if (!$m || ($m['kind'] ?? 'internal') !== 'tenant' || !subscription_enabled()) {
        return true;
    }
    return !empty($m['paid_until']) && $m['paid_until'] >= now();
}

/** Jours restants (0 si expiré ou jamais payé). */
function subscription_days_left(array $m): int
{
    if (empty($m['paid_until']) || $m['paid_until'] < now()) {
        return 0;
    }
    return (int)ceil((strtotime($m['paid_until'] . ' UTC') - time()) / 86400);
}

/** Prolonge l'abonnement : à partir de la fin actuelle si encore actif, sinon à partir de maintenant. */
function subscription_extend(int $merchantId, int $days): string
{
    $m = q_one('SELECT paid_until FROM merchants WHERE id = ?', [$merchantId]);
    $from = !empty($m['paid_until']) && $m['paid_until'] > now() ? strtotime($m['paid_until'] . ' UTC') : time();
    $until = gmdate('Y-m-d H:i:s', $from + $days * 86400);
    db_update('merchants', ['paid_until' => $until], 'id = :id', ['id' => $merchantId]);
    return $until;
}

/** Crée le paiement d'abonnement (encaissé sur les numéros de la plateforme). */
function subscription_create_payment(array $m, string $payerPhone): array
{
    return payment_create([
        'amount' => subscription_price(),
        'payer_phone' => $payerPhone,
        'description' => 'Abonnement ' . app_name() . ' — ' . $m['name'] . ' — ' . subscription_days() . ' jours',
        'reference' => 'ABO-' . $m['id'],
        'metadata' => ['subscription_for' => (int)$m['id'], 'days' => subscription_days()],
        'return_url' => abs_url('espace/subscription'),
    ], null); // null = paiement de la plateforme (vos numéros, votre téléphone)
}

/** Appelé quand un paiement est confirmé : active l'abonnement s'il s'agit d'un paiement d'abonnement. */
function subscription_on_paid(array $payment): void
{
    $meta = $payment['metadata'] ? json_decode((string)$payment['metadata'], true) : null;
    if (!is_array($meta) || empty($meta['subscription_for']) || !empty($payment['merchant_id'])) {
        return;
    }
    $mid = (int)$meta['subscription_for'];
    $m = q_one('SELECT * FROM merchants WHERE id = ?', [$mid]);
    if (!$m) {
        return;
    }
    $until = subscription_extend($mid, max(1, (int)($meta['days'] ?? subscription_days())));
    log_line("subscription merchant #$mid until $until ({$payment['reference']})");
    push_notify('✅ Abonnement activé', 'Votre abonnement est actif jusqu\'au ' . fmt_dt($until) . '.', 'subscription', 'sub-' . $payment['reference'], $mid);
    push_notify('💳 Abonnement payé : ' . $m['name'], fmt_amount($payment['amount'], 'F') . ' · actif jusqu\'au ' . fmt_dt($until), 'merchants', 'sub-' . $payment['reference'], null);
}
