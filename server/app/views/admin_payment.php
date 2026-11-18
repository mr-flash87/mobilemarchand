<?php $pub = payment_public($pay); ?>
<div class="row"><h1 style="margin:0">Paiement <span class="mono"><?= h($pay['reference']) ?></span></h1> <?= badge($pay['status']) ?></div>
<p></p>
<div class="grid g2">
  <div class="card">
    <h2><?= h(fmt_amount($pay['amount'], 'F CFA')) ?></h2>
    <table>
      <tr><th>Marchand</th><td><?= h($merchant['name'] ?? '—') ?></td></tr>
      <tr><th>Réf. marchand</th><td><?= h($pay['external_ref'] ?? '—') ?></td></tr>
      <tr><th>Payeur</th><td><?= h($pay['payer_phone']) ?></td></tr>
      <tr><th>Opérateur</th><td><?php $oc = $pay['paid_operator'] ?: (operator_by_id($pay['operator_id'] ?? 0)['code'] ?? null); $oo = $oc ? operator_by_code($oc) : null; ?><?= $oo ? h($oo['name']) . ($pay['paid_operator'] ? ' (payé)' : ' (imposé)') : 'Au choix du client' ?></td></tr>
      <tr><th>Description</th><td><?= h($pay['description'] ?? '—') ?></td></tr>
      <tr><th>Code USSD</th><td class="mono"><?= h($pub['ussd_code']) ?></td></tr>
      <tr><th>Lien client</th><td><a href="<?= h($pub['checkout_url']) ?>" target="_blank"><?= h($pub['checkout_url']) ?></a></td></tr>
      <tr><th>Créé</th><td><?= h(fmt_dt($pay['created_at'])) ?></td></tr>
      <tr><th>Expire</th><td><?= h(fmt_dt($pay['expires_at'])) ?></td></tr>
      <tr><th>Payé</th><td><?= h(fmt_dt($pay['paid_at'])) ?></td></tr>
      <tr><th>ID transaction</th><td class="mono"><?= h($pay['operator_txn_id'] ?? '—') ?></td></tr>
      <?php if ($pay['metadata']): ?><tr><th>Metadata</th><td class="mono small"><?= h($pay['metadata']) ?></td></tr><?php endif; ?>
    </table>
    <div class="row" style="margin-top:14px">
      <?php if (in_array($pay['status'], ['pending', 'expired'], true)): ?>
        <form method="post" class="inline" onsubmit="return confirm('Confirmer que l\'argent a bien été reçu ?')"><?= csrf_field() ?>
          <button class="btn ok" name="action" value="mark_paid">Marquer comme payé</button></form>
      <?php endif; ?>
      <?php if ($pay['status'] === 'pending'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><button class="btn danger" name="action" value="cancel">Annuler</button></form>
      <?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn" name="action" value="resend">Renvoyer le webhook</button></form>
    </div>
  </div>
  <div class="card">
    <h2>SMS de confirmation</h2>
    <?php if ($sms): ?>
      <p><strong><?= h($sms['sender']) ?></strong> · <span class="muted small"><?= h(fmt_dt($sms['received_at'])) ?></span></p>
      <div class="sms-body"><?= h($sms['body']) ?></div>
      <?php if ($sms['note']): ?><p class="small muted" style="margin-top:8px"><?= h($sms['note']) ?></p><?php endif; ?>
    <?php else: ?>
      <p class="muted"><?= $pay['status'] === 'paid' ? 'Validé manuellement (sans SMS).' : 'Pas encore reçu.' ?></p>
    <?php endif; ?>
  </div>
</div>
<?php if ($pay['status'] === 'paid' || $refunds): ?>
<div class="card" id="refund">
  <h2>Remboursement</h2>
  <?php foreach ($refunds as $r): ?>
    <p class="small"><?= payout_emoji($r['status']) ?> <strong><?= h(fmt_amount($r['amount'], 'F')) ?></strong> vers <?= h($r['phone']) ?> ·
      <?= h(payout_status_label($r['status'])) ?> · <span class="mono"><?= h($r['reference']) ?></span> · <span class="muted"><?= h(fmt_dt($r['created_at'])) ?></span>
      <?php if ($r['result_text']): ?><br><span class="muted">« <?= h($r['result_text']) ?> »</span><?php endif; ?></p>
  <?php endforeach; ?>
  <?php if ($pay['status'] === 'paid'): ?>
    <form method="post" class="row" style="gap:10px;flex-wrap:wrap" onsubmit="return confirm('Rembourser ce client ? Le téléphone passerelle enverra l\'argent.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="refund">
      <input type="number" name="refund_amount" min="1" placeholder="Montant (vide = tout)" style="max-width:200px">
      <input type="text" name="reason" placeholder="Motif (ex. commande annulée)" style="flex:1;min-width:180px">
      <button class="btn danger">↩️ Rembourser le client</button>
    </form>
    <p class="small muted">L'argent est renvoyé au numéro et par l'opérateur qui ont payé. Le téléphone passerelle compose le code USSD et saisit le code secret.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="card table-wrap">
  <h2>Webhooks envoyés</h2>
  <table>
    <tr><th>Événement</th><th>URL</th><th>Statut</th><th>Essais</th><th>Réponse</th><th>Date</th></tr>
    <?php foreach ($hooks as $w): ?>
      <tr>
        <td class="mono small"><?= h($w['event']) ?></td>
        <td class="small"><?= h($w['url']) ?></td>
        <td><?= badge($w['status']) ?></td>
        <td><?= (int)$w['attempts'] ?></td>
        <td class="small mono"><?= h(($w['response_code'] ?? '') . ' ' . mb_strimwidth((string)$w['response_body'], 0, 80, '…')) ?></td>
        <td class="small muted"><?= h(fmt_dt($w['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$hooks): ?><tr><td colspan="6" class="muted">Aucun (pas d'URL de webhook configurée ou pas encore d'événement).</td></tr><?php endif; ?>
  </table>
</div>
