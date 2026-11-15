<?php view('_head', ['title' => $title, 'noindex' => true]); ?>
<div class="checkout">
  <div class="card">
    <h1><?= h($title) ?></h1>
    <p class="muted"><?= h($text) ?></p>
    <a class="btn" href="<?= h(url('')) ?>">Retour à l'accueil</a>
  </div>
</div>
</body></html>
