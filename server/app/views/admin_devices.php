<h1>Téléphones passerelle</h1>
<div class="grid g2">
  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>Ajouter un téléphone</h2>
    <label>Nom</label><input type="text" name="name" placeholder="Ex. Samsung A10 - Orange" required>
    <label>Numéro de la SIM marchande</label><input type="tel" name="sim_number" placeholder="70000000">
    <label>Solde actuel de chaque compte (pour le contrôle anti-fraude)</label>
    <div class="bal-grid">
      <?php foreach (operators(true) as $o): ?>
        <span class="bal-in" style="--op:<?= h($o['color']) ?>"><span><?= h($o['name']) ?></span>
          <input type="text" name="balances[<?= h($o['code']) ?>]" placeholder="ex. 15000"></span>
      <?php endforeach; ?>
    </div>
    <p class="small muted">Remplissez seulement les opérateurs dont la SIM est dans ce téléphone (un téléphone double SIM peut en avoir deux). Le solde est ensuite mis à jour à chaque SMS.</p>
    <button class="btn primary">Ajouter et obtenir le code</button>
  </form>
  <div class="card">
    <h2>Installer l'application</h2>
    <ol class="steps">
      <li>Sur le téléphone Android qui a la SIM marchande, téléchargez l'application :
        <a href="<?= h(url('downloads/smspay-gateway.apk')) ?>"><?= h(abs_url('downloads/smspay-gateway.apk')) ?></a>
        (autorisez « sources inconnues »).</li>
      <li>Ouvrez-la, entrez l'adresse du serveur <code><?= h(abs_url('')) ?></code> et le code d'association à 6 chiffres.</li>
      <li>Acceptez les permissions SMS et notifications, et désactivez l'optimisation de batterie pour l'application.</li>
      <li>Laissez le téléphone branché, avec Internet (data ou Wi-Fi).</li>
    </ol>
  </div>
</div>
<div class="card table-wrap">
  <table>
    <tr><th>Téléphone</th><th>État</th><th>Code d'association</th><th>Soldes de référence</th><th>Actions</th></tr>
    <?php foreach ($rows as $d): $on = device_online($d); ?>
      <tr>
        <td><strong><?= h($d['name']) ?></strong><br><span class="small muted"><?= h($d['sim_number']) ?> · <?= (int)$d['sms_count'] ?> SMS · app <?= h($d['app_version'] ?: '—') ?></span></td>
        <td><?= $d['active'] ? badge($on ? 'online' : 'offline') : badge('cancelled') ?>
          <br><span class="small muted">Vu : <?= h(fmt_dt($d['last_seen_at'])) ?></span>
          <?php if ($d['battery'] !== null): ?><br><span class="small">🔋 <?= (int)$d['battery'] ?>%<?= $d['charging'] ? ' ⚡' : '' ?></span><?php endif; ?></td>
        <td>
          <?php if ($d['active'] && $d['pair_code'] && $d['pair_expires_at'] >= now()): ?>
            <span class="key" style="display:inline-block;font-size:1.3rem;letter-spacing:.15em"><?= h($d['pair_code']) ?></span>
            <br><span class="small muted">jusqu'à <?= h(fmt_dt($d['pair_expires_at'])) ?></span>
          <?php elseif ($d['active']): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn sm" name="action" value="code">Nouveau code</button></form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($d['active']): ?>
            <form method="post" class="bal-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <?php foreach (operators(true) as $o): $bv = device_balance($d, $o['code']); ?>
                <span class="bal-in sm" style="--op:<?= h($o['color']) ?>"><span><?= h($o['name']) ?></span>
                  <input type="text" name="balances[<?= h($o['code']) ?>]" value="<?= $bv !== null ? h($bv) : '' ?>" placeholder="—"></span>
              <?php endforeach; ?>
              <button class="btn sm" name="action" value="balance">OK</button></form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($d['active']): ?>
            <form method="post" class="inline" onsubmit="return confirm('Révoquer ce téléphone ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn sm danger" name="action" value="revoke">Révoquer</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Aucun téléphone.</td></tr><?php endif; ?>
  </table>
</div>
