<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($title ?? '') !== '' ? $title . ' · ' . app_name() : app_name()) ?></title>
<meta name="robots" content="<?= !empty($noindex) ? 'noindex' : 'index' ?>">
<link rel="manifest" href="<?= h(url('manifest.webmanifest') . (ctx()['type'] === 'merchant' ? '?area=espace' : '')) ?>">
<meta name="theme-color" content="#0b1224">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(url('assets/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(url('assets/apple-touch-icon.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h(app_name()) ?>">
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>?v=<?= @filemtime(APP_ROOT . '/assets/style.css') ?: APP_VERSION ?>">
</head>
<body>
