<?php
$bot = tg_username();
$enabled = tg_enabled();
$isAdmin = is_admin_ctx();
?>
<div class="page-head">
  <div>
    <h1>Telegram</h1>
    <p class="muted">Votre tableau de bord dans Telegram, en privé ou dans un groupe : alerte à chaque paiement reçu, statistiques, paiements en attente, SMS à vérifier, téléphones, création de liens de paiement…</p>
  </div>
  <?php if ($enabled && $bot): ?><a class="btn" href="https://t.me/<?= h($bot) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Ouvrir @<?= h($bot) ?></a><?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <form class="card" method="post">
    <?= csrf_field() ?>
    <h2>1. Le bot de la plateforme</h2>
    <?php if ($enabled): ?>
      <p><span class="badge b-paid">Connecté</span> Bot <strong>@<?= h($bot) ?></strong> : utilisé par vous et par vos marchands inscrits (chacun ne voit que ses données).</p>
      <?php if ($webhook): ?>
        <p class="small muted">Webhook : <code><?= h($webhook['url'] ?? '') ?></code>
          <?php if (!empty($webhook['pending_update_count'])): ?> · <?= (int)$webhook['pending_update_count'] ?> message(s) en attente<?php endif; ?>
          <?php if (!empty($webhook['last_error_message'])): ?><br><span style="color:var(--err)">Dernière erreur : <?= h($webhook['last_error_message']) ?> (<?= h(fmt_dt(gmdate('Y-m-d H:i:s', (int)$webhook['last_error_date']))) ?>)</span><?php endif; ?></p>
      <?php endif; ?>
      <div class="row">
        <input type="password" name="token" placeholder="Nouveau jeton (pour changer de bot)" autocomplete="off" style="flex:1;min-width:220px">
        <button class="btn" name="action" value="setup">Reconnecter</button>
        <button class="btn danger" name="action" value="disable" onclick="return confirm('Désactiver le bot Telegram ?')">Désactiver</button>
      </div>
    <?php else: ?>
      <ol class="small" style="line-height:1.8;padding-left:18px">
        <li>Dans Telegram, ouvrez <a href="https://t.me/BotFather" target="_blank" rel="noopener"><strong>@BotFather</strong></a> et envoyez <code>/newbot</code>.</li>
        <li>Choisissez un nom (ex. <em><?= h(app_name()) ?></em>) puis un identifiant qui finit par <code>bot</code> (ex. <em><?= h(strtolower(preg_replace('/\W+/', '', app_name()))) ?>_paiement_bot</em>).</li>
        <li>BotFather vous donne un <strong>jeton</strong> (ex. <code>123456789:AAH…</code>) : collez-le ci-dessous. Ne le donnez à personne d'autre.</li>
      </ol>
      <div class="row">
        <input type="password" name="token" placeholder="123456789:AAH…" autocomplete="off" required style="flex:1;min-width:220px">
        <button class="btn primary" name="action" value="setup">Connecter le bot</button>
      </div>
    <?php endif; ?>
  </form>
<?php elseif (!$enabled): ?>
  <div class="callout"><?= icon('alert', 18) ?><div>Le bot Telegram n'est pas encore activé sur la plateforme. Revenez bientôt, ou contactez le support.</div></div>
<?php endif; ?>

<?php if ($enabled): ?>
  <section class="card" id="lier">
    <h2><?= $isAdmin ? '2. ' : '' ?>Relier une discussion ou un groupe</h2>
    <?php if ($code): ?>
      <div class="callout" style="border-color:var(--brand)"><?= icon('check', 18) ?><div>
        <div>Code valable 30 minutes, une seule fois : <strong style="font-size:1.25em;letter-spacing:.06em"><code><?= h($code) ?></code></strong></div>
        <div class="row" style="margin-top:10px">
          <a class="btn primary" href="https://t.me/<?= h($bot) ?>?start=<?= h($code) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> En privé (moi seul)</a>
          <a class="btn" href="https://t.me/<?= h($bot) ?>?startgroup=<?= h($code) ?>" target="_blank" rel="noopener"><?= icon('plus', 16) ?> Ajouter à un groupe</a>
        </div>
        <p class="small muted" style="margin:10px 0 0">Si le lien ne relie pas le groupe : ajoutez <strong>@<?= h($bot) ?></strong> au groupe, puis un administrateur du groupe envoie <code>/lier <?= h($code) ?></code>.</p>
      </div></div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="code">
        <p class="small muted">Générez un code, puis touchez le lien depuis votre téléphone : Telegram s'ouvre et relie la discussion à <?= $isAdmin ? 'l\'administration' : 'votre espace' ?>.
          Dans un groupe (ex. vos caissiers), tout le monde voit les alertes, mais <strong>seuls les administrateurs du groupe</strong> peuvent valider, annuler ou créer des paiements.</p>
        <label class="check"><input type="checkbox" name="read_only" value="1"> <span>Lecture seule : alertes et consultation uniquement (aucune action possible depuis Telegram)</span></label>
        <button class="btn primary"><?= icon('plus', 16) ?> Générer un code de liaison</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="row"><h2 style="margin:0">Discussions reliées</h2><span class="spacer"></span>
      <?php if ($chats): ?><form method="post" style="margin:0"><?= csrf_field() ?><button class="btn sm" name="action" value="test">Envoyer un test</button></form><?php endif; ?></div>
    <?php if (!$chats): ?>
      <p class="muted small">Aucune discussion pour l'instant.</p>
    <?php else: ?>
      <?php foreach ($chats as $c): ?>
        <form method="post" class="row" style="flex-wrap:wrap;gap:10px 18px;padding:12px 0;border-top:1px solid var(--line)">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <div style="min-width:180px;flex:1"><strong><?= h($c['title']) ?></strong><br>
            <span class="small muted"><?= $c['chat_type'] === 'private' ? 'Discussion privée' : 'Groupe' ?> · depuis le <?= h(substr(fmt_dt($c['created_at']), 0, 10)) ?></span></div>
          <label class="check" style="margin:0"><input type="checkbox" name="notify" value="1" <?= (int)$c['notify'] ? 'checked' : '' ?>> <span>Alertes</span></label>
          <label class="check" style="margin:0"><input type="checkbox" name="can_act" value="1" <?= (int)$c['can_act'] ? 'checked' : '' ?>> <span>Valider / annuler / créer</span></label>
          <div class="row" style="gap:8px"><button class="btn sm primary" name="action" value="save_chat">Enregistrer</button>
            <button class="btn sm danger" name="action" value="unlink" onclick="return confirm('Délier cette discussion ?')">Délier</button></div>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Ce que vous pouvez faire dans Telegram</h2>
    <div class="grid g2 small">
      <div><strong>🔔 Alertes automatiques</strong><br>Paiement reçu, SMS à vérifier (solde incohérent), argent reçu sans paiement<?= $isAdmin ? ', nouveau marchand inscrit, abonnement payé' : ', abonnement activé' ?>.</div>
      <div><strong>🏠 /menu</strong><br>Tableau de bord avec boutons : encaissements du jour, paiements en attente, SMS à traiter.</div>
      <div><strong>➕ /creer 5000</strong><br>Crée un lien de paiement à envoyer au client (numéro et description en option : <code>/creer 5000 70123456 Recharge</code>).</div>
      <div><strong>💳 /paiements · /attente</strong><br>Derniers paiements, détail, <em>Marquer payé</em> ou <em>Annuler</em> en un bouton.</div>
      <div><strong>🔎 /sms</strong><br>Valider un SMS « à vérifier », ignorer, ou <code>/rapprocher 12 PAY-…</code>.</div>
      <div><strong>📱 /telephones · 🏦 /operateurs</strong><br>Téléphones en ligne, batterie, soldes ; activer / désactiver un opérateur.</div>
      <div><strong>📊 /stats</strong><br>Jour, hier, 7 et 30 jours, taux de réussite, répartition par opérateur.</div>
      <div><strong><?= $isAdmin ? '🏪 /marchands' : '📅 /abonnement' ?></strong><br><?= $isAdmin ? 'Valider les comptes en attente, abonnements qui expirent bientôt.' : 'Jours restants et lien pour renouveler.' ?></div>
    </div>
  </section>
<?php endif; ?>
