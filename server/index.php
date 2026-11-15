<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/api.php';
require APP_DIR . '/admin.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim(rawurldecode($path), '/');
if ($path === '/index.php') {
    $path = '/';
}
$parts = array_values(array_filter(explode('/', $path), 'strlen'));

try {
    switch ($parts[0] ?? '') {
        case '':
            view('home');
            break;
        case 'manifest.webmanifest':
            header('Content-Type: application/manifest+json; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo json_encode([
                'name' => app_name() . ' – Espace marchand',
                'short_name' => app_name(),
                'description' => 'Suivi des paiements Mobile Money et notifications en temps réel.',
                'id' => url('admin'),
                'start_url' => url('admin'),
                'scope' => url('') ,
                'display' => 'standalone',
                'orientation' => 'portrait',
                'background_color' => '#0b1224',
                'theme_color' => '#0b1224',
                'lang' => 'fr',
                'icons' => [
                    ['src' => url('assets/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => url('assets/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                    ['src' => url('assets/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        case 'docs':
            view('docs');
            break;
        case 'pay':
            $p = isset($parts[1]) ? payment_find($parts[1]) : null;
            if (!$p) {
                http_response_code(404);
                view('message', ['title' => 'Paiement introuvable', 'text' => 'Ce lien de paiement n\'existe pas.']);
                break;
            }
            view('checkout', ['p' => $p]);
            break;
        case 'api':
            $scope = $parts[1] ?? '';
            $rest = array_slice($parts, 2);
            if ($scope === 'v1') {
                api_merchant($method, $rest);
            } elseif ($scope === 'device') {
                api_device($method, $rest);
            } elseif ($scope === 'public') {
                api_public($method, $rest);
            }
            json_error('not_found', 'Route inconnue.', 404);
        case 'cron':
            $key = (string)setting('cron_key');
            if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
                http_response_code(403);
                exit('forbidden');
            }
            json_out(run_maintenance());
        case 'admin':
            admin_route($method, array_slice($parts, 1));
            break;
        default:
            http_response_code(404);
            view('message', ['title' => 'Page introuvable', 'text' => 'Cette page n\'existe pas.']);
    }
} catch (Throwable $e) {
    log_line('ERROR ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (($parts[0] ?? '') === 'api') {
        json_error('server_error', 'Erreur interne du serveur.', 500);
    }
    http_response_code(500);
    $debug = (bool)cfg('debug', false);
    view('message', ['title' => 'Erreur', 'text' => $debug ? $e->getMessage() : 'Une erreur est survenue.']);
}
