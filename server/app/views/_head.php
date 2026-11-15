<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($title ?? '') !== '' ? $title . ' · ' . app_name() : app_name()) ?></title>
<meta name="robots" content="<?= !empty($noindex) ? 'noindex' : 'index' ?>">
<link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body>
