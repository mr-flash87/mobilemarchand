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
<section class="card ios-card" id="ios-guide">
  <div class="row" style="gap:12px">
    <span class="tile-ic blue"><?= icon('phone') ?></span>
    <div style="flex:1;min-width:200px">
      <h2 style="margin:0">Votre SIM est dans un iPhone (eSIM) ?</h2>
      <p class="small muted" style="margin:2px 0 0">L'iPhone peut envoyer lui-même les SMS de paiement à votre site grâce à l'app <strong>Raccourcis</strong> d'Apple (iOS 17 ou plus récent). Pas besoin de téléphone Android.</p>
    </div>
  </div>

  <?php if ($iosToken): ?>
    <div class="alert ok" style="margin-top:14px">Clé de l'iPhone (affichée une seule fois, copiez-la maintenant) :</div>
    <div class="key" id="ios-key">Bearer <?= h($iosToken) ?></div>
    <button class="btn sm" type="button" style="margin-top:8px" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('ios-key').textContent);this.textContent='Copié ✓'">Copier la clé</button>
  <?php else: ?>
    <form method="post" class="row" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="create_ios">
      <input type="text" name="name" placeholder="Nom (ex. iPhone de Cesar)" style="flex:1;min-width:160px">
      <input type="tel" name="sim_number" placeholder="Numéro de la eSIM" style="flex:1;min-width:140px">
      <button class="btn primary">Ajouter un iPhone</button>
    </form>
  <?php endif; ?>

  <details class="ios-steps" <?= $iosToken ? 'open' : '' ?>>
    <summary>Guide : créer l'automatisation sur l'iPhone (5 minutes)</summary>
    <ol class="steps">
      <li>Ouvrez l'app <strong>Raccourcis</strong> → onglet <strong>Automatisation</strong> → <strong>+</strong> (Nouvelle automatisation).</li>
      <li>Choisissez <strong>Message</strong>. Dans « Le message contient », tapez <code>FCFA</code>. Laissez « Expéditeur » vide.</li>
      <li>Cochez <strong>Exécuter immédiatement</strong> (et désactivez « M'avertir lors de l'exécution »), puis <strong>Suivant</strong> → <strong>Nouveau raccourci vide</strong>.</li>
      <li>Ajoutez l'action <strong>Obtenir le contenu de l'URL</strong> et mettez l'adresse :
        <div class="key" style="margin:6px 0"><?= h(abs_url('api/device/sms')) ?></div></li>
      <li>Touchez la flèche <strong>›</strong> de l'action pour voir les options :
        <ul>
          <li><strong>Méthode</strong> : <code>POST</code></li>
          <li><strong>En-têtes</strong> → Ajouter : clé <code>Authorization</code>, valeur <code>Bearer dev_…</code> (la clé ci-dessus)</li>
          <li><strong>Corps de la requête</strong> : <code>JSON</code> → ajoutez 2 champs <em>Texte</em> :
            <br><code>sender</code> = la variable <strong>Message</strong>, puis touchez-la et choisissez <strong>Expéditeur</strong>
            <br><code>body</code> = la variable <strong>Message</strong>, puis touchez-la et choisissez <strong>Contenu</strong></li>
        </ul></li>
      <li>Touchez <strong>OK</strong>. C'est prêt : chaque SMS de paiement reçu sur l'iPhone sera envoyé à votre site.</li>
    </ol>
    <div class="callout warn"><?= icon('alert', 18) ?><div>
      L'iPhone doit avoir Internet au moment où le SMS arrive (pas de renvoi automatique s'il est hors ligne : validez alors le paiement à la main dans « SMS reçus »).
      Seuls les SMS contenant « FCFA » sont envoyés ; ceux qui ne viennent pas d'un opérateur sont ignorés par le site.
      Pour tester : envoyez-vous un SMS contenant « FCFA » depuis un autre téléphone, il doit apparaître dans <a href="<?= h(aurl('sms')) ?>">SMS reçus</a> (en « Ignoré », c'est normal).
    </div></div>
  </details>
</section>

<div class="card table-wrap">
  <table>
    <tr><th>Téléphone</th><th>État</th><th>Code d'association</th><th>Soldes de référence</th><th>Actions</th></tr>
    <?php foreach ($rows as $d): $on = device_online($d); ?>
      <tr>
        <td><strong><?= h($d['name']) ?></strong><?php if (($d['kind'] ?? '') === 'ios'): ?> <span class="badge">iPhone · Raccourcis</span><?php endif; ?><br><span class="small muted"><?= h($d['sim_number']) ?> · <?= (int)$d['sms_count'] ?> SMS · app <?= h($d['app_version'] ?: '—') ?></span></td>
        <td><?= $d['active'] ? badge($on ? 'online' : 'offline') : badge('cancelled') ?>
          <br><span class="small muted">Vu : <?= h(fmt_dt($d['last_seen_at'])) ?></span>
          <?php if ($d['battery'] !== null): ?><br><span class="small">🔋 <?= (int)$d['battery'] ?>%<?= $d['charging'] ? ' ⚡' : '' ?></span><?php endif; ?></td>
        <td>
          <?php if (($d['kind'] ?? '') === 'ios' && $d['active']): ?>
            <form method="post" class="inline" onsubmit="return confirm('L\'ancienne clé ne marchera plus. Continuer ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn sm" name="action" value="ios_token">Nouvelle clé</button></form>
          <?php elseif ($d['active'] && $d['pair_code'] && $d['pair_expires_at'] >= now()): ?>
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
