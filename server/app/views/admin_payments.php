<h1>Paiements</h1>
<div class="grid g2">
  <form class="card" method="get">
    <h2>Rechercher</h2>
    <div class="row">
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Référence, téléphone, ID transaction…" style="flex:1;min-width:180px">
      <select name="status" style="width:auto">
        <option value="">Tous</option>
        <?php foreach (['pending', 'paid', 'expired', 'cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn">Filtrer</button>
    </div>
  </form>
  <form class="card" id="new" method="post" action="<?= h(aurl('payments/new')) ?>">
    <?= csrf_field() ?>
    <h2>Créer un lien de paiement</h2>
    <div class="row">
      <input type="number" name="amount" placeholder="Montant" required min="1" style="flex:1;min-width:110px">
      <input type="tel" name="payer_phone" placeholder="Numéro du client" required style="flex:1;min-width:140px">
    </div>
    <div class="row" style="margin-top:8px">
      <input type="text" name="description" placeholder="Description (optionnel)" style="flex:1;min-width:160px">
      <?php if (is_admin_ctx()): ?><select name="merchant_id" style="width:auto">
        <option value="">Sans marchand</option>
        <?php foreach ($merchants as $m): ?><option value="<?= (int)$m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
      </select><?php endif; ?>
      <button class="btn primary">Créer</button>
    </div>
  </form>
</div>
<div class="card table-wrap">
  <table>
    <tr><th>Référence</th><th>Marchand</th><th>Montant</th><th>Payeur</th><th>Opérateur</th><th>Statut</th><th>Créé</th><th>Payé</th></tr>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td><a class="mono small" href="<?= h(aurl('payments/' . $p['reference'])) ?>"><?= h($p['reference']) ?></a>
          <?php if ($p['external_ref']): ?><br><span class="small muted"><?= h($p['external_ref']) ?></span><?php endif; ?></td>
        <td class="small"><?= h($p['merchant_name'] ?? '—') ?></td>
        <td><strong><?= h(fmt_amount($p['amount'])) ?></strong></td>
        <td class="small"><?= h($p['payer_phone']) ?></td>
        <td class="small"><?php $oc = $p['paid_operator'] ?: (operator_by_id($p['operator_id'] ?? 0)['code'] ?? null); $oo = $oc ? operator_by_code($oc) : null; ?><?= $oo ? '<span class="bal-chip" style="--op:' . h($oo['color']) . '">' . h($oo['name']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td><?= badge($p['status']) ?></td>
        <td class="small muted"><?= h(fmt_dt($p['created_at'])) ?></td>
        <td class="small muted"><?= h(fmt_dt($p['paid_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Aucun paiement.</td></tr><?php endif; ?>
  </table>
</div>
