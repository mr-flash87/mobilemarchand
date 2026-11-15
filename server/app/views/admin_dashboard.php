<?php
$tz = new DateTimeZone((string)setting('timezone', 'Africa/Ouagadougou'));
$hour = (int)(new DateTime('now', $tz))->format('G');
$hello = $hour < 12 ? 'Bonjour' : ($hour < 18 ? 'Bon après-midi' : 'Bonsoir');
$setupDone = count(array_filter($setup, fn($s) => $s['done']));
$delta = null;
if ($stats['paid_yesterday'] > 0) {
    $delta = round(100 * ($stats['paid_today'] - $stats['paid_yesterday']) / $stats['paid_yesterday']);
}
// Échelle du graphique : maximum arrondi à une valeur "ronde".
$max = max(array_column($days, 'amount')) ?: 0;
$niceMax = 1000;
if ($max > 0) {
    $pow = 10 ** floor(log10($max));
    foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
        if ($m * $pow >= $max) { $niceMax = $m * $pow; break; }
    }
}
$todayKey = array_key_last($days);
?>
<div class="page-head">
  <div>
    <h1><?= h($hello) ?> 👋</h1>
    <p class="muted">Voici l'activité de votre passerelle <?= h(setting('operator_name')) ?>.</p>
  </div>
  <div class="row">
    <a class="btn" href="<?= h(url('admin/simulate')) ?>"><?= icon('flask', 16) ?> Simuler un SMS</a>
    <a class="btn primary" href="<?= h(url('admin/payments')) ?>#new"><?= icon('plus', 16) ?> Nouveau paiement</a>
  </div>
</div>

<?php if ($setupDone < count($setup)): ?>
<section class="card setup">
  <div class="row">
    <div>
      <h2 style="margin:0">Mise en route</h2>
      <p class="muted small" style="margin:2px 0 0"><?= $setupDone ?> étape<?= $setupDone > 1 ? 's' : '' ?> sur <?= count($setup) ?> terminée<?= $setupDone > 1 ? 's' : '' ?></p>
    </div>
    <span class="spacer"></span>
    <div class="progress" aria-hidden="true"><span style="width:<?= round(100 * $setupDone / count($setup)) ?>%"></span></div>
  </div>
  <ol class="checklist">
    <?php foreach ($setup as $s): ?>
      <li class="<?= $s['done'] ? 'done' : '' ?>">
        <span class="tick"><?= $s['done'] ? icon('check', 14) : '' ?></span>
        <?php if ($s['done']): ?><span><?= h($s['label']) ?></span>
        <?php else: ?><a href="<?= h(url($s['href'])) ?>"><?= h($s['label']) ?> <?= icon('arrow', 14) ?></a><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<div class="kpis">
  <section class="hero-tile">
    <div class="hero-top"><?= icon('wallet', 18) ?> Encaissé aujourd'hui</div>
    <div class="hero-num"><?= h(fmt_amount($stats['paid_today'], '')) ?><small>F CFA</small></div>
    <div class="hero-sub">
      <?= (int)$stats['count_today'] ?> paiement<?= $stats['count_today'] > 1 ? 's' : '' ?>
      <?php if ($delta !== null): ?>
        · <span class="delta <?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>% vs hier</span>
      <?php else: ?>
        · hier : <?= h(fmt_amount($stats['paid_yesterday'])) ?>
      <?php endif; ?>
    </div>
  </section>
  <a class="tile" href="<?= h(url('admin/payments?status=pending')) ?>">
    <span class="tile-ic amber"><?= icon('clock') ?></span>
    <span class="tile-v"><?= (int)$stats['pending'] ?></span>
    <span class="tile-l">Paiements en attente</span>
  </a>
  <a class="tile" href="<?= h(url('admin/sms?status=unmatched')) ?>">
    <span class="tile-ic <?= $stats['to_review'] ? 'red' : 'green' ?>"><?= icon($stats['to_review'] ? 'alert' : 'check') ?></span>
    <span class="tile-v"><?= (int)$stats['to_review'] ?></span>
    <span class="tile-l">SMS à traiter</span>
  </a>
  <div class="tile">
    <span class="tile-ic blue"><?= icon('chart') ?></span>
    <span class="tile-v"><?= $stats['success_rate'] !== null ? (int)$stats['success_rate'] . '%' : '—' ?></span>
    <span class="tile-l">Taux de réussite (7 j)</span>
  </div>
</div>

<div class="dash-grid">
  <section class="card">
    <div class="row">
      <div>
        <h2 style="margin:0">Encaissements des 7 derniers jours</h2>
        <p class="muted small" style="margin:2px 0 0">Total : <strong class="ink"><?= h(fmt_amount($stats['week_total'], 'F CFA')) ?></strong> · depuis le début : <?= h(fmt_amount($stats['paid_total'])) ?></p>
      </div>
    </div>
    <div class="chart" role="img" aria-label="Montants encaissés par jour sur 7 jours">
      <div class="chart-grid">
        <?php foreach ([1, 0.5, 0] as $f): ?>
          <div class="gl" style="bottom:<?= $f * 100 ?>%"><span><?= h(fmt_amount($niceMax * $f, '')) ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="bars">
        <?php foreach ($days as $k => $d): $hgt = $niceMax > 0 ? max($d['amount'] > 0 ? 2 : 0, 100 * $d['amount'] / $niceMax) : 0; ?>
          <div class="bar-col <?= $k === $todayKey ? 'is-today' : '' ?>" tabindex="0">
            <div class="bar" style="height:<?= round($hgt, 2) ?>%"></div>
            <div class="tip"><strong><?= h(fmt_amount($d['amount'], 'F')) ?></strong><br><?= (int)$d['count'] ?> paiement<?= $d['count'] > 1 ? 's' : '' ?> · <?= h($d['dow'] . ' ' . $d['label']) ?></div>
            <?php if ($k === $todayKey && $d['amount'] > 0): ?><div class="bar-val" style="bottom:<?= round($hgt, 2) ?>%"><?= h(fmt_amount($d['amount'], '')) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="bars-x">
      <?php foreach ($days as $k => $d): ?><span class="<?= $k === $todayKey ? 'ink' : '' ?>"><?= h($k === $todayKey ? "Aujourd'hui" : $d['dow']) ?></span><?php endforeach; ?>
    </div>
    <details class="small muted" style="margin-top:10px"><summary>Voir en tableau</summary>
      <table><tr><th>Jour</th><th>Paiements</th><th>Montant</th></tr>
        <?php foreach ($days as $d): ?><tr><td><?= h($d['dow'] . ' ' . $d['label']) ?></td><td><?= (int)$d['count'] ?></td><td><?= h(fmt_amount($d['amount'])) ?></td></tr><?php endforeach; ?>
      </table></details>
  </section>

  <section class="card">
    <div class="row"><h2 style="margin:0">Passerelle</h2><span class="spacer"></span><a class="link-sm" href="<?= h(url('admin/devices')) ?>">Gérer <?= icon('arrow', 14) ?></a></div>
    <?php if (!$devices): ?>
      <div class="empty">
        <span class="tile-ic blue"><?= icon('phone') ?></span>
        <p>Aucun téléphone associé.</p>
        <a class="btn sm primary" href="<?= h(url('admin/devices')) ?>">Ajouter un téléphone</a>
      </div>
    <?php endif; ?>
    <?php foreach ($devices as $d): $on = device_online($d); ?>
      <div class="device">
        <div class="device-ic <?= $on ? 'on' : '' ?>"><?= icon('phone', 20) ?></div>
        <div class="device-body">
          <div class="row"><strong><?= h($d['name']) ?></strong><span class="spacer"></span><?= badge($on ? 'online' : 'offline') ?></div>
          <div class="small muted"><?= h($d['sim_number'] ?: '—') ?> · vu <?= h(fmt_dt($d['last_seen_at'])) ?></div>
          <div class="device-meta">
            <?php if ($d['battery'] !== null): ?>
              <span class="batt"><span class="batt-fill <?= $d['battery'] < 20 ? 'low' : '' ?>" style="width:<?= (int)$d['battery'] ?>%"></span></span>
              <span class="small"><?= (int)$d['battery'] ?>%<?= $d['charging'] ? ' ⚡' : '' ?></span>
            <?php endif; ?>
            <span class="spacer"></span>
            <span class="small">Solde : <strong><?= $d['last_balance'] !== null ? h(fmt_amount($d['last_balance'])) : '—' ?></strong></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="api-card">
      <div class="row"><?= icon('book') ?><strong>Intégrer l'API</strong></div>
      <p class="small">Donnez ce lien aux sites qui veulent encaisser via votre plateforme :</p>
      <div class="copy-line"><code id="docs-url"><?= h(abs_url('docs')) ?></code>
        <button class="btn sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('docs-url').textContent);this.textContent='Copié ✓'">Copier</button></div>
      <a class="btn sm" href="<?= h(url('docs')) ?>" target="_blank">Ouvrir la documentation <?= icon('arrow', 14) ?></a>
    </div>
  </section>
</div>

<div class="dash-grid even">
  <section class="card">
    <div class="row"><h2 style="margin:0">Derniers paiements</h2><span class="spacer"></span><a class="link-sm" href="<?= h(url('admin/payments')) ?>">Tout voir <?= icon('arrow', 14) ?></a></div>
    <div class="list">
      <?php foreach ($recent as $p): ?>
        <a class="list-row" href="<?= h(url('admin/payments/' . $p['reference'])) ?>">
          <span class="avatar sm s-<?= h($p['status']) ?>"><?= icon($p['status'] === 'paid' ? 'check' : 'clock', 14) ?></span>
          <span class="list-main"><strong><?= h($p['payer_phone']) ?></strong><span class="small muted"><?= h($p['merchant_name'] ?? 'Sans marchand') ?> · <?= h(fmt_dt($p['created_at'])) ?></span></span>
          <span class="list-end"><strong><?= h(fmt_amount($p['amount'])) ?></strong><?= badge($p['status']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$recent): ?><div class="empty"><p>Aucun paiement pour l'instant.</p><a class="btn sm" href="<?= h(url('admin/simulate')) ?>">Faire un test avec le simulateur</a></div><?php endif; ?>
    </div>
  </section>
  <section class="card">
    <div class="row"><h2 style="margin:0">Derniers SMS</h2><span class="spacer"></span><a class="link-sm" href="<?= h(url('admin/sms')) ?>">Tout voir <?= icon('arrow', 14) ?></a></div>
    <div class="list">
      <?php foreach ($sms as $s): ?>
        <div class="list-row">
          <span class="avatar sm"><?= icon('sms', 14) ?></span>
          <span class="list-main"><strong><?= h($s['sender']) ?></strong><span class="small muted clamp"><?= h($s['body']) ?></span></span>
          <span class="list-end"><?php if ($s['amount'] !== null): ?><strong><?= h(fmt_amount($s['amount'])) ?></strong><?php endif; ?><?= badge($s['status']) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$sms): ?><div class="empty"><p>Aucun SMS reçu pour l'instant.</p></div><?php endif; ?>
    </div>
  </section>
</div>
