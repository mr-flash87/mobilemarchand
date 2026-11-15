<header class="lp-nav">
  <div class="lp-wrap lp-nav-in">
    <a class="lp-logo" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 18) ?></span><?= h(app_name()) ?></a>
    <nav class="lp-links">
      <a href="<?= h(url('')) ?>#fonctionnement">Fonctionnement</a>
      <a href="<?= h(url('')) ?>#securite">Sécurité</a>
      <a href="<?= h(url('docs')) ?>">Documentation</a>
    </nav>
    <div class="lp-actions">
      <a class="btn primary" href="<?= h(url('espace/connexion')) ?>">Se connecter</a>
    </div>
  </div>
</header>
