<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('APP_VERSION', '1.0.0');

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli') {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        header('Location: ' . $base . '/install.php');
        exit;
    }
    fwrite(STDERR, "config.php introuvable. Lancez install.php.\n");
    exit(1);
}

$GLOBALS['config'] = require $configFile;
date_default_timezone_set('UTC');

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/settings.php';
require APP_DIR . '/sms_parser.php';
require APP_DIR . '/operators.php';
require APP_DIR . '/subscription.php';
require APP_DIR . '/payments.php';
require APP_DIR . '/webhooks.php';
require APP_DIR . '/webpush.php';
require APP_DIR . '/telegram.php';

db_migrate();
