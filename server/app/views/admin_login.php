<?php view('_head', ['title' => 'Connexion', 'noindex' => true]); ?>
<div class="checkout">
  <h1 class="logo" style="text-align:center;font-size:1.6rem"><?= h(app_name()) ?><span>.</span></h1>
  <?php foreach (flash() as [$type, $msg]): ?><div class="alert <?= h($type) ?>"><?= h($msg) ?></div><?php endforeach; ?>
  <form class="card" method="post">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" type="email" name="email" required autofocus autocomplete="username">
    <label for="password">Mot de passe</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">
    <p></p>
    <button class="btn primary big">Se connecter</button>
  </form>
</div>
</body></html>
