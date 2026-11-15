<?php view('_head', ['title' => $title, 'noindex' => true]);
$nav = [
    '' => ['Tableau de bord', 'home'],
    'payments' => ['Paiements', 'card'],
    'sms' => ['SMS reçus', 'sms'],
    'merchants' => ['Marchands & API', 'store'],
    'operators' => ['Opérateurs', 'wallet'],
    'devices' => ['Téléphones', 'phone'],
    'simulate' => ['Simulateur', 'flask'],
    'settings' => ['Réglages', 'gear'],
];
$current = explode('/', trim(substr((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(base_path())), '/'))[1] ?? '';
$toReview = (int)q_val("SELECT COUNT(*) FROM sms_messages WHERE status IN ('unmatched','review')");
$online = (int)q_val('SELECT COUNT(*) FROM devices WHERE active = 1 AND last_seen_at >= ?', [ts_plus(-600)]);
$hasDevice = (int)q_val('SELECT COUNT(*) FROM devices WHERE active = 1') > 0;
?>
<div class="shell">
  <aside class="side" id="side">
    <a class="side-brand" href="<?= h(url('admin')) ?>">
      <span class="brand-mark"><?= icon('wallet', 18) ?></span>
      <span><?= h(app_name()) ?></span>
    </a>
    <nav class="side-nav">
      <div class="side-label">Gestion</div>
      <?php foreach ($nav as $k => [$label, $ic]): ?>
        <a class="<?= $current === $k ? 'on' : '' ?>" href="<?= h(url('admin' . ($k ? '/' . $k : ''))) ?>">
          <?= icon($ic) ?><span><?= h($label) ?></span>
          <?php if ($k === 'sms' && $toReview > 0): ?><em class="pill"><?= $toReview ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <div class="side-label">Intégration</div>
      <a href="<?= h(url('docs')) ?>" target="_blank"><?= icon('book') ?><span>Documentation API</span></a>
      <a href="<?= h(url('')) ?>" target="_blank"><?= icon('globe') ?><span>Voir le site</span></a>
    </nav>
    <div class="side-foot">
      <button class="side-install" type="button" data-pwa-install hidden><?= icon('phone', 16) ?> Installer l'application</button>
      <div class="gw-status <?= $online ? 'is-on' : ($hasDevice ? 'is-off' : '') ?>">
        <span class="dot"></span>
        <span><?= $online ? 'Passerelle en ligne' : ($hasDevice ? 'Passerelle hors ligne' : 'Aucun téléphone') ?></span>
      </div>
      <div class="side-user">
        <span class="avatar"><?= h(strtoupper(substr((string)($user['email'] ?? 'A'), 0, 1))) ?></span>
        <span class="side-email"><?= h($user['email'] ?? '') ?></span>
        <form method="post" action="<?= h(url('admin/logout')) ?>"><?= csrf_field() ?>
          <button class="icon-btn" title="Déconnexion" aria-label="Déconnexion"><?= icon('logout') ?></button></form>
      </div>
    </div>
  </aside>
  <div class="side-backdrop" onclick="document.body.classList.remove('nav-open')"></div>

  <div class="main">
    <header class="mbar">
      <button class="icon-btn" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <strong><?= h(app_name()) ?></strong>
      <span class="spacer"></span>
      <a class="icon-btn" href="<?= h(url('docs')) ?>" target="_blank" aria-label="Documentation"><?= icon('book') ?></a>
    </header>
    <main class="content">
      <?php foreach (flash() as [$type, $msg]): ?>
        <div class="alert <?= h($type) ?>"><?= h($msg) ?></div>
      <?php endforeach; ?>
      <?php view($view, get_defined_vars()); ?>
    </main>
  </div>
</div>
<script>window.FP = <?= json_encode(['base' => base_path(), 'csrf' => csrf_token(), 'vapid' => webpush_supported() ? vapid_public_b64u() : '']) ?>;</script>
<script src="<?= h(url('assets/pwa.js')) ?>?v=<?= @filemtime(APP_ROOT . '/assets/pwa.js') ?: APP_VERSION ?>" defer></script>
</body></html>
