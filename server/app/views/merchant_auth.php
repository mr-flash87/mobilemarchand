<?php
$signup = $mode === 'signup';
view('_head', ['title' => $signup ? 'Créer un compte marchand' : 'Connexion marchand', 'noindex' => true]);
?>
<div class="auth">
  <aside class="auth-side">
    <a class="lp-logo light" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 18) ?></span><?= h(app_name()) ?></a>
    <div class="auth-pitch">
      <h2><?= $signup ? 'Encaissez sur vos propres numéros Mobile Money.' : 'Votre espace marchand.' ?></h2>
      <ul>
        <li><?= icon('check', 16) ?> L'argent arrive directement sur <strong>vos</strong> numéros</li>
        <li><?= icon('check', 16) ?> Confirmation automatique par SMS ou notification</li>
        <li><?= icon('check', 16) ?> API, page de paiement et webhooks pour votre site</li>
        <li><?= icon('check', 16) ?> Orange Money, Moov, Telecel, Wave…</li>
      </ul>
    </div>
    <div class="auth-card-demo">
      <div class="row" style="gap:10px"><span class="lp-ok"><?= icon('check', 16) ?></span>
        <div><strong>Paiement confirmé</strong><div class="small" style="opacity:.75">il y a quelques secondes</div></div>
        <span class="spacer"></span><strong>12 500 F</strong></div>
    </div>
  </aside>
  <main class="auth-main">
    <div class="auth-box">
      <a class="lp-logo auth-logo-sm" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 18) ?></span><?= h(app_name()) ?></a>
      <h1><?= $signup ? 'Créer un compte' : 'Connexion' ?></h1>
      <p class="muted"><?= $signup ? 'Gratuit. Votre compte sera validé rapidement par notre équipe.' : 'Accédez à votre espace marchand.' ?></p>
      <?php if (($error ?? '') === 'admin'): ?>
        <div class="alert warn">Cet email est celui d'un <strong>administrateur</strong>. Connectez-vous à l'espace administrateur :
          <a class="btn sm primary" style="margin-top:8px" href="<?= h(url('admin/login')) ?>">Aller à l'administration</a></div>
      <?php elseif (!empty($error)): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
      <?php foreach (flash() as [$type, $msg]): ?><div class="alert <?= h($type) ?>"><?= h($msg) ?></div><?php endforeach; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <?php if ($signup): ?>
          <label for="name">Nom de l'entreprise ou du site</label>
          <input id="name" type="text" name="name" required value="<?= h($old['name']) ?>" placeholder="Ex. Ma Boutique" autocomplete="organization">
          <label for="phone">Téléphone (WhatsApp)</label>
          <input id="phone" type="tel" name="phone" required value="<?= h($old['phone']) ?>" placeholder="Ex. 70 00 00 00" autocomplete="tel">
        <?php endif; ?>
        <label for="email">Adresse email</label>
        <input id="email" type="email" name="email" required value="<?= h($old['email'] ?? '') ?>" placeholder="vous@exemple.com" autocomplete="username">
        <label for="password">Mot de passe<?= $signup ? ' (8 caractères minimum)' : '' ?></label>
        <input id="password" type="password" name="password" required <?= $signup ? 'minlength="8" autocomplete="new-password"' : 'autocomplete="current-password"' ?> placeholder="••••••••">
        <?php if ($signup): ?>
          <label for="password2">Confirmer le mot de passe</label>
          <input id="password2" type="password" name="password2" required minlength="8" autocomplete="new-password" placeholder="••••••••">
          <div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="company_url" tabindex="-1" autocomplete="off"></div>
          <label class="check" style="margin-top:14px"><input type="checkbox" name="terms" value="1" required>
            <span class="small">J'accepte les conditions d'utilisation et je confirme être le titulaire des numéros Mobile Money que j'utiliserai.</span></label>
        <?php endif; ?>
        <button class="btn primary big" style="margin-top:18px"><?= $signup ? 'Créer mon compte' : 'Se connecter' ?> <?= icon('arrow', 16) ?></button>
      </form>
      <p class="small muted auth-foot">
        <?php if ($signup): ?>Déjà inscrit ? <a href="<?= h(url('espace/connexion')) ?>">Se connecter</a>
        <?php else: ?>Pas encore de compte ? <a href="<?= h(url('espace/inscription')) ?>">Créer un compte gratuit</a><?php endif; ?>
        <br><a href="<?= h(url('')) ?>">← Retour à l'accueil</a> · <a href="<?= h(url('docs')) ?>">Documentation API</a><?php if (!$signup): ?> · <a href="<?= h(url('admin/login')) ?>">Espace administrateur</a><?php endif; ?>
      </p>
    </div>
  </main>
</div>
</body></html>
