<?php view('_head', ['title' => 'Connexion', 'noindex' => true]); ?>
<div class="auth">
  <aside class="auth-side">
    <a class="lp-logo light" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 18) ?></span><?= h(app_name()) ?></a>
    <div class="auth-pitch">
      <h2>Vos paiements Mobile Money, confirmés automatiquement.</h2>
      <ul>
        <li><?= icon('check', 16) ?> Suivi en temps réel de vos encaissements</li>
        <li><?= icon('check', 16) ?> Validation des SMS et anti-fraude</li>
        <li><?= icon('check', 16) ?> Clés API et webhooks pour vos sites</li>
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
      <h1>Connexion</h1>
      <p class="muted">Accédez à votre espace de gestion.</p>
      <?php foreach (flash() as [$type, $msg]): ?><div class="alert <?= h($type) ?>"><?= h($msg) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label for="email">Adresse email</label>
        <input id="email" type="email" name="email" required autofocus autocomplete="username" placeholder="vous@exemple.com">
        <label for="password">Mot de passe</label>
        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
        <button class="btn primary big" style="margin-top:18px">Se connecter <?= icon('arrow', 16) ?></button>
      </form>
      <p class="small muted auth-foot"><a href="<?= h(url('')) ?>">← Retour à l'accueil</a> · <a href="<?= h(url('docs')) ?>">Documentation API</a></p>
    </div>
  </main>
</div>
</body></html>
