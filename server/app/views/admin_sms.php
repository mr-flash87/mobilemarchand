<h1>SMS reçus</h1>
<div class="row" style="margin-bottom:14px">
  <?php foreach (['' => 'Tous', 'unmatched' => 'Non rapprochés', 'review' => 'À vérifier', 'matched' => 'Rapprochés', 'duplicate' => 'Doublons', 'info' => 'Info solde', 'ignored' => 'Ignorés'] as $k => $l): ?>
    <a class="btn sm <?= $status === $k ? 'primary' : '' ?>" href="<?= h(url('admin/sms' . ($k ? '?status=' . $k : ''))) ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</div>
<div class="alert warn small">
  <strong>Non rapproché</strong> : l'argent est arrivé mais aucun paiement en attente ne correspond (mauvais numéro, montant différent, client parti…). Saisissez la référence du paiement pour le valider.<br>
  <strong>À vérifier</strong> : le solde annoncé ne correspond pas au solde attendu. Vérifiez sur votre compte <?= h(setting('operator_name')) ?> que l'argent est bien là avant de valider : ce peut être un faux SMS.
</div>
<div class="card table-wrap">
  <table>
    <tr><th>Reçu</th><th>SMS</th><th>Lu</th><th>Statut</th><th>Action</th></tr>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td class="small muted"><?= h(fmt_dt($s['received_at'])) ?><br>#<?= (int)$s['id'] ?></td>
        <td><strong class="small"><?= h($s['sender']) ?></strong><div class="sms-body"><?= h($s['body']) ?></div></td>
        <td class="small">
          <?php if ($s['amount'] !== null): ?>Montant : <strong><?= h(fmt_amount($s['amount'])) ?></strong><br><?php endif; ?>
          <?php if ($s['phone']): ?>De : <?= h($s['phone']) ?><br><?php endif; ?>
          <?php if ($s['txn_id']): ?>Txn : <span class="mono"><?= h($s['txn_id']) ?></span><br><?php endif; ?>
          <?php if ($s['balance'] !== null): ?>Solde : <?= h(fmt_amount($s['balance'])) ?><br><?php endif; ?>
          <span class="muted"><?= h($s['rule_name'] ?? '') ?></span>
        </td>
        <td><?= badge($s['status']) ?>
          <?php if ($s['payment_ref']): ?><br><a class="small mono" href="<?= h(url('admin/payments/' . $s['payment_ref'])) ?>"><?= h($s['payment_ref']) ?></a><?php endif; ?>
          <?php if ($s['note']): ?><br><span class="small muted"><?= h($s['note']) ?></span><?php endif; ?></td>
        <td style="min-width:200px">
          <?php if ($s['status'] === 'review'): ?>
            <form method="post" action="<?= h(url('admin/sms/' . $s['id'])) ?>" onsubmit="return confirm('Avez-vous vérifié sur votre compte que l\'argent est bien arrivé ?')">
              <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($status) ?>">
              <button class="btn sm ok" name="action" value="approve">Argent vérifié, valider</button>
            </form>
          <?php endif; ?>
          <?php if (in_array($s['status'], ['unmatched', 'review'], true)): ?>
            <form method="post" action="<?= h(url('admin/sms/' . $s['id'])) ?>" class="row" style="margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($status) ?>">
              <input type="text" name="reference" placeholder="PAY-…" style="width:130px">
              <button class="btn sm" name="action" value="match">Rapprocher</button>
            </form>
            <form method="post" action="<?= h(url('admin/sms/' . $s['id'])) ?>" style="margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="back" value="<?= h($status) ?>">
              <button class="btn sm danger" name="action" value="ignore">Ignorer</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Aucun SMS.</td></tr><?php endif; ?>
  </table>
</div>
