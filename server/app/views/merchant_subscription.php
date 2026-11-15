<?php
$left = subscription_days_left($m);
$active = $left > 0;
$ops = array_values(array_filter(operators(true, null), 'operator_ready'));
?>
<div class="page-head">
  <div>
    <h1>Abonnement</h1>
    <p class="muted">Un abonnement actif est nécessaire pour encaisser des paiements sur vos numéros.</p>
  </div>
</div>

<div class="grid g2">
  <section class="card sub-status <?= $active ? 'is-on' : 'is-off' ?>">
    <span class="tile-ic <?= $active ? 'green' : 'red' ?>"><?= icon($active ? 'check' : 'alert') ?></span>
    <h2 style="margin:10px 0 4px"><?= $active ? 'Abonnement actif' : 'Abonnement inactif' ?></h2>
    <?php if ($active): ?>
      <p>Valable jusqu'au <strong><?= h(fmt_dt($m['paid_until'])) ?></strong><br><span class="muted"><?= $left ?> jour<?= $left > 1 ? 's' : '' ?> restant<?= $left > 1 ? 's' : '' ?></span></p>
    <?php else: ?>
      <p class="muted"><?= $m['paid_until'] ? 'Expiré depuis le ' . h(fmt_dt($m['paid_until'])) . '.' : 'Vous n\'avez pas encore d\'abonnement.' ?>
        Vos clients ne peuvent pas payer tant que l'abonnement n'est pas actif.</p>
    <?php endif; ?>
    <?php if ($m['status'] !== 'active'): ?>
      <p class="small alert warn" style="margin-top:10px">Votre compte est aussi en attente de validation par l'administrateur.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="sub-price"><?= h(fmt_amount(subscription_price(), '')) ?><small>F CFA / <?= subscription_days() ?> jours</small></div>
    <ul class="lp-list" style="margin:12px 0 16px">
      <li><?= icon('check', 16) ?> Paiements illimités sur vos numéros</li>
      <li><?= icon('check', 16) ?> Confirmation automatique par SMS ou notification</li>
      <li><?= icon('check', 16) ?> API, page de paiement, webhooks et notifications</li>
    </ul>
    <?php if (!$ops): ?>
      <div class="alert warn">Le paiement de l'abonnement n'est pas encore disponible. Contactez le support.</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Numéro avec lequel vous allez payer</label>
        <input type="tel" name="payer_phone" required value="<?= h($m['phone']) ?>" placeholder="Ex. 70 00 00 00">
        <p class="small muted">Le paiement est reconnu grâce à ce numéro. Moyens acceptés : <?= h(implode(', ', array_column($ops, 'name'))) ?>.</p>
        <button class="btn primary big"><?= $active ? 'Prolonger de ' . subscription_days() . ' jours' : 'Payer ' . h(fmt_amount(subscription_price(), 'F')) ?> <?= icon('arrow', 16) ?></button>
      </form>
      <?php if ($active): ?><p class="small muted" style="margin-top:8px">Les jours payés s'ajoutent à la fin de votre abonnement actuel.</p><?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<section class="card table-wrap">
  <h2>Historique</h2>
  <table>
    <tr><th>Date</th><th>Montant</th><th>Statut</th><th></th></tr>
    <?php foreach ($history as $h): ?>
      <tr>
        <td class="small"><?= h(fmt_dt($h['created_at'])) ?></td>
        <td><strong><?= h(fmt_amount($h['amount'])) ?></strong></td>
        <td><?= badge($h['status']) ?></td>
        <td><?php if ($h['status'] === 'pending'): ?><a class="btn sm" href="<?= h(url('pay/' . $h['reference'])) ?>">Reprendre le paiement</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="4" class="muted">Aucun paiement d'abonnement.</td></tr><?php endif; ?>
  </table>
</section>
