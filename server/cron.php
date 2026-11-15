<?php
// Tâche planifiée : à lancer chaque minute → php /chemin/vers/cron.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
echo json_encode(run_maintenance()), "\n";
