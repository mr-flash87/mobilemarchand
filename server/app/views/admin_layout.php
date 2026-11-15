<?php view('_head', ['title' => $title, 'noindex' => true]);
$nav = ['' => 'Tableau de bord', 'payments' => 'Paiements', 'sms' => 'SMS', 'merchants' => 'Marchands', 'devices' => 'Téléphones', 'simulate' => 'Simuler', 'settings' => 'Réglages'];
$current = explode('/', trim(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(base_path())), '/'))[1] ?? '';
?>
<header class="topbar">
  <div class="wrap">
    <a class="logo" href="<?= h(url('admin')) ?>"><?= h(app_name()) ?><span>.</span></a>
    <nav class="nav">
      <?php foreach ($nav as $k => $label): ?>
        <a class="<?= $current === $k ? 'on' : '' ?>" href="<?= h(url('admin' . ($k ? '/' . $k : ''))) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <span class="spacer"></span>
    <form method="post" action="<?= h(url('admin/logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn sm">Déconnexion</button></form>
  </div>
</header>
<main class="wrap">
  <?php foreach (flash() as [$type, $msg]): ?>
    <div class="alert <?= h($type) ?>"><?= h($msg) ?></div>
  <?php endforeach; ?>
  <?php view($view, get_defined_vars()); ?>
</main>
</body></html>
