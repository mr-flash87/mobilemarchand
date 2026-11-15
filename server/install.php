<?php
declare(strict_types=1);
/**
 * Installateur : crée la base, le compte admin et config.php.
 * Supprimez ce fichier après l'installation (il se bloque de lui-même une fois config.php créé).
 */
define('APP_ROOT', __DIR__);
define('APP_DIR', __DIR__ . '/app');
require APP_DIR . '/db.php';

$configFile = APP_ROOT . '/config.php';
$done = is_file($configFile);
$error = null;
$ok = false;

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$checks = [
    'PHP 8.0 ou plus' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Extension PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extension cURL' => extension_loaded('curl'),
    'Extension mbstring' => extension_loaded('mbstring'),
    'Dossier inscriptible' => is_writable(APP_ROOT),
];

if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $driver = ($_POST['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    $db = $driver === 'sqlite'
        ? ['driver' => 'sqlite', 'path' => APP_ROOT . '/data/database.sqlite']
        : [
            'driver' => 'mysql',
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim($_POST['db_name'] ?? ''),
            'user' => trim($_POST['db_user'] ?? ''),
            'pass' => (string)($_POST['db_pass'] ?? ''),
        ];
    $email = trim($_POST['admin_email'] ?? '');
    $pass = (string)($_POST['admin_pass'] ?? '');
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email admin invalide.';
    } elseif (strlen($pass) < 8) {
        $error = 'Le mot de passe admin doit faire au moins 8 caractères.';
    } elseif ($appUrl !== '' && !filter_var($appUrl, FILTER_VALIDATE_URL)) {
        $error = 'Adresse du site invalide.';
    } else {
        try {
            if (!is_dir(APP_ROOT . '/data')) {
                mkdir(APP_ROOT . '/data', 0775, true);
            }
            file_put_contents(APP_ROOT . '/data/.htaccess', "Require all denied\nDeny from all\n");
            $GLOBALS['config'] = ['db' => $db];
            $pdo = db();
            db_install_schema($pdo, $driver);
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
                $st->execute([password_hash($pass, PASSWORD_DEFAULT), $email]);
            } else {
                $st = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)');
                $st->execute([$email, password_hash($pass, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s')]);
            }
            $config = [
                'app_url' => $appUrl,
                'app_secret' => bin2hex(random_bytes(32)),
                'debug' => false,
                'db' => $db,
            ];
            $php = "<?php\n// Généré par install.php le " . gmdate('Y-m-d H:i') . " UTC. NE PAS PARTAGER.\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($configFile, $php) === false) {
                throw new RuntimeException('Impossible d\'écrire config.php (droits du dossier).');
            }
            @chmod($configFile, 0640);
            $ok = true;
        } catch (Throwable $ex) {
            $error = 'Erreur : ' . $ex->getMessage();
        }
    }
}
$guessUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation</title><meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/style.css"></head>
<body><div class="checkout" style="max-width:560px">
<h1>Installation</h1>
<?php if ($ok): ?>
  <div class="alert ok">Installation terminée ✅</div>
  <div class="card">
    <p><strong>Important :</strong> supprimez maintenant le fichier <code>install.php</code> de votre hébergement.</p>
    <a class="btn primary big" href="admin/login">Aller à l'administration</a>
  </div>
<?php elseif ($done): ?>
  <div class="alert warn">Déjà installé. Supprimez <code>install.php</code>. Pour réinstaller, supprimez d'abord <code>config.php</code>.</div>
  <a class="btn" href="admin">Administration</a>
<?php else: ?>
  <div class="card">
    <h2>Vérifications</h2>
    <?php foreach ($checks as $label => $pass): ?>
      <div><?= $pass ? '✅' : '❌' ?> <?= e($label) ?></div>
    <?php endforeach; ?>
  </div>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <form class="card" method="post">
    <h2>Base de données</h2>
    <p class="small muted">Créez une base MySQL et un utilisateur dans votre panneau d'hébergement (cPanel → Bases de données MySQL), puis remplissez :</p>
    <input type="hidden" name="driver" value="<?= e($_POST['driver'] ?? 'mysql') ?>">
    <label>Serveur</label><input type="text" name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>">
    <label>Port</label><input type="number" name="db_port" value="<?= e($_POST['db_port'] ?? '3306') ?>">
    <label>Nom de la base</label><input type="text" name="db_name" value="<?= e($_POST['db_name'] ?? '') ?>">
    <label>Utilisateur</label><input type="text" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>">
    <label>Mot de passe</label><input type="password" name="db_pass">
    <h2 style="margin-top:18px">Site & administrateur</h2>
    <label>Adresse du site</label><input type="url" name="app_url" value="<?= e($_POST['app_url'] ?? $guessUrl) ?>">
    <label>Email admin</label><input type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required>
    <label>Mot de passe admin (8 caractères min.)</label><input type="password" name="admin_pass" required minlength="8">
    <p></p><button class="btn primary big">Installer</button>
  </form>
<?php endif; ?>
</div></body></html>
