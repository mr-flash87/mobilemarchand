<h1>Marchands</h1>

<section class="card">
  <div class="row"><h2 style="margin:0">Comptes inscrits (espace marchand)</h2><span class="spacer"></span>
    <span class="small muted">Lien d'inscription : <a href="<?= h(url('espace/inscription')) ?>" target="_blank"><?= h(abs_url('espace/inscription')) ?></a></span></div>
  <p class="small muted">Ils utilisent leurs propres numéros et téléphones. Validez-les avant qu'ils puissent encaisser.</p>
  <div class="table-wrap"><table>
    <tr><th>Marchand</th><th>Contact</th><th>Statut</th><th>Abonnement</th><th>Configuration</th><th>Encaissé</th><th>Action</th></tr>
    <?php foreach ($tenants as $t): ?>
      <tr>
        <td><strong><?= h($t['name']) ?></strong><br><span class="small muted">inscrit le <?= h(fmt_dt($t['created_at'])) ?></span></td>
        <td class="small"><?= h($t['email']) ?><br><?= h($t['phone']) ?>
          <?php $wa = preg_replace('/\D+/', '', (string)$t['phone']); if ($wa): ?> · <a href="https://wa.me/<?= h(strlen($wa) === 8 ? '226' . $wa : $wa) ?>" target="_blank">WhatsApp</a><?php endif; ?></td>
        <td><?= $t['status'] === 'active' ? '<span class="badge b-paid">Validé</span>' : ($t['status'] === 'pending' ? '<span class="badge b-pending">En attente</span>' : '<span class="badge b-cancelled">Suspendu</span>') ?></td>
        <td class="small"><?php $left = subscription_days_left($t); ?>
          <?= $left > 0 ? '<span class="badge b-paid">Actif</span><br>jusqu\'au ' . h(fmt_dt($t['paid_until'])) : '<span class="badge b-expired">' . ($t['paid_until'] ? 'Expiré' : 'Non payé') . '</span>' ?>
          <form method="post" class="row" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="number" name="days" value="<?= subscription_days() ?>" min="1" max="366" style="width:64px;padding:4px 6px">
            <button class="btn sm" name="action" value="gift" title="Ajouter des jours gratuitement">Offrir</button>
            <?php if ($left > 0): ?><button class="btn sm danger" name="action" value="end_sub" onclick="return confirm('Arrêter l\'abonnement maintenant ?')">Arrêter</button><?php endif; ?>
          </form></td>
        <td class="small"><?= (int)$t['operator_count'] ?> opérateur(s) actif(s)<br><?= (int)$t['device_count'] ?> téléphone(s)</td>
        <td class="small"><?= (int)$t['count_paid'] ?> paiements<br><strong><?= h(fmt_amount($t['total_paid'])) ?></strong></td>
        <td>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <?php if ($t['status'] === 'pending'): ?>
              <button class="btn sm ok" name="action" value="approve">Valider</button>
              <button class="btn sm danger" name="action" value="suspend" onclick="return confirm('Refuser et suspendre ce compte ?')">Refuser</button>
            <?php elseif ($t['status'] === 'active'): ?>
              <button class="btn sm danger" name="action" value="suspend" onclick="return confirm('Suspendre ce compte ?')">Suspendre</button>
            <?php else: ?>
              <button class="btn sm" name="action" value="reactivate">Réactiver</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tenants): ?><tr><td colspan="7" class="muted">Aucune inscription pour l'instant.</td></tr><?php endif; ?>
  </table></div>
</section>

<h2 style="margin-top:28px">Vos marchands (passerelle de la plateforme)</h2>
<p class="muted">Ils encaissent sur <strong>vos</strong> numéros et vos téléphones. Créez-en un pour vos propres sites.</p>

<?php if ($newKey): ?>
  <div class="card" style="border-color:var(--brand)">
    <h2>🔑 Clé API (affichée une seule fois)</h2>
    <div class="key"><?= h($newKey) ?></div>
    <p class="small muted" style="margin-top:8px">Transmettez-la au marchand avec son secret de webhook et le lien vers la documentation : <a href="<?= h(abs_url('docs')) ?>"><?= h(abs_url('docs')) ?></a></p>
  </div>
<?php endif; ?>

<form class="card" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>Nouveau marchand</h2>
  <div class="grid g2">
    <div><label>Nom</label><input type="text" name="name" required placeholder="Ex. Ma boutique"></div>
    <div><label>URL de webhook (optionnel)</label><input type="url" name="webhook_url" placeholder="https://site.com/webhook"></div>
    <div><label>Email</label><input type="email" name="email"></div>
    <div><label>Téléphone</label><input type="tel" name="phone"></div>
  </div>
  <p></p><button class="btn primary">Créer et générer la clé</button>
</form>

<?php foreach ($rows as $m): ?>
  <div class="card">
    <div class="row">
      <h2 style="margin:0"><?= h($m['name']) ?></h2>
      <?= $m['active'] ? badge('online') : badge('cancelled') ?>
      <span class="spacer"></span>
      <span class="small muted"><?= (int)$m['count_paid'] ?> paiements · <?= h(fmt_amount($m['total_paid'])) ?></span>
    </div>
    <p class="small muted" style="margin-top:6px">Clé : <span class="mono"><?= h($m['api_key_prefix']) ?>…</span> · Secret webhook : <span class="mono key" style="display:inline-block;padding:2px 6px"><?= h($m['webhook_secret']) ?></span></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <div class="grid g2">
        <div><label>Nom</label><input type="text" name="name" value="<?= h($m['name']) ?>"></div>
        <div><label>URL de webhook</label><input type="url" name="webhook_url" value="<?= h($m['webhook_url']) ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= h($m['email']) ?>"></div>
        <div><label>Téléphone</label><input type="tel" name="phone" value="<?= h($m['phone']) ?>"></div>
      </div>
      <div class="row" style="margin-top:12px">
        <button class="btn primary" name="action" value="update">Enregistrer</button>
        <button class="btn" name="action" value="test">Tester le webhook</button>
        <button class="btn" name="action" value="rotate" onclick="return confirm('L\'ancienne clé cessera de fonctionner. Continuer ?')">Nouvelle clé API</button>
        <button class="btn danger" name="action" value="toggle"><?= $m['active'] ? 'Désactiver' : 'Activer' ?></button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
