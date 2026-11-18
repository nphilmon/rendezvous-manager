<?php
declare(strict_types=1);

// Purge des journaux de synchronisation, sessions expirées et tentatives de connexion anciennes.
// Cron conseillé : 17 3 * * * php /chemin/workers/cleanup_worker.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\Maintenance;

try {
    $res = (new Maintenance(Database::connection()))->run((int) Config::get('app.sync.retention_days', 30));
    Logger::log('info', 'cleanup', $res);
    echo json_encode($res), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
