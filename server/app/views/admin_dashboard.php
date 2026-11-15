<h1>Tableau de bord</h1>
<?php foreach ($warnings as $w): ?><div class="alert warn"><?= h($w) ?></div><?php endforeach; ?>

<div class="grid g4">
  <div class="card stat"><div class="v"><?= h(fmt_amount($stats['paid_today'])) ?></div><div class="l">Encaissé aujourd'hui (<?= (int)$stats['count_today'] ?>)</div></div>
  <div class="card stat"><div class="v"><?= (int)$stats['pending'] ?></div><div class="l">Paiements en attente</div></div>
  <div class="card stat"><div class="v"><a href="<?= h(url('admin/sms?status=unmatched')) ?>"><?= (int)$stats['to_review'] ?></a></div><div class="l">SMS à traiter</div></div>
  <div class="card stat"><div class="v"><?= h(fmt_amount($stats['paid_total'])) ?></div><div class="l">Total encaissé</div></div>
</div>

<div class="card">
  <div class="row"><h2 style="margin:0">Téléphones passerelle</h2><span class="spacer"></span><a class="btn sm" href="<?= h(url('admin/devices')) ?>">Gérer</a></div>
  <?php if (!$devices): ?><p class="muted" style="margin-top:10px">Aucun téléphone.</p><?php endif; ?>
  <div class="table-wrap"><table>
    <?php foreach ($devices as $d): $on = device_online($d); ?>
      <tr>
        <td><strong><?= h($d['name']) ?></strong> <span class="muted small"><?= h($d['sim_number']) ?></span></td>
        <td><?= badge($on ? 'online' : 'offline') ?></td>
        <td class="small muted">Vu : <?= h(fmt_dt($d['last_seen_at'])) ?></td>
        <td class="small"><?= $d['battery'] !== null ? '🔋 ' . (int)$d['battery'] . '%' . ($d['charging'] ? ' ⚡' : '') : '' ?></td>
        <td class="small">Solde : <?= $d['last_balance'] !== null ? h(fmt_amount($d['last_balance'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="row"><h2 style="margin:0">Derniers paiements</h2><span class="spacer"></span><a class="btn sm" href="<?= h(url('admin/payments')) ?>">Tout voir</a></div>
    <div class="table-wrap"><table>
      <?php foreach ($recent as $p): ?>
        <tr>
          <td><a class="mono small" href="<?= h(url('admin/payments/' . $p['reference'])) ?>"><?= h($p['reference']) ?></a><br><span class="small muted"><?= h($p['merchant_name'] ?? '—') ?></span></td>
          <td><strong><?= h(fmt_amount($p['amount'])) ?></strong><br><span class="small muted"><?= h($p['payer_phone']) ?></span></td>
          <td><?= badge($p['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td class="muted">Aucun paiement.</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="card">
    <div class="row"><h2 style="margin:0">Derniers SMS</h2><span class="spacer"></span><a class="btn sm" href="<?= h(url('admin/sms')) ?>">Tout voir</a></div>
    <div class="table-wrap"><table>
      <?php foreach ($sms as $s): ?>
        <tr>
          <td class="small"><strong><?= h($s['sender']) ?></strong><br><span class="muted"><?= h(mb_strimwidth($s['body'], 0, 90, '…')) ?></span></td>
          <td><?= badge($s['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$sms): ?><tr><td class="muted">Aucun SMS reçu.</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
