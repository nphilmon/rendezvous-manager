<?php
declare(strict_types=1);

// Sauvegarde planifiée de la base (fichier .sql.gz + contrôle SHA-256), vérification puis rotation.
// Cron conseillé (chaque nuit) : 30 2 * * * php /chemin/workers/backup_worker.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\AuditLogger;
use App\Services\Backup\BackupService;

try {
    $pdo = Database::connection();
    $svc = new BackupService($pdo, (string) Config::get('app.backup.dir'), (string) Config::get('app.version'));
    $meta = $svc->create(null);
    $check = $svc->verify($svc->path($meta['name']));
    if (!$check['ok']) {
        throw new RuntimeException('Vérification échouée : ' . implode(' ', $check['problems']));
    }
    $pruned = $svc->prune((int) Config::get('app.backup.keep', 14));
    (new AuditLogger($pdo))->log('backup.create', null, null, null, 'cli', ['name' => $meta['name'], 'size' => $meta['size'], 'rows' => $meta['rows'], 'pruned' => count($pruned)]);
    Logger::log('info', 'backup', ['name' => $meta['name'], 'size' => $meta['size'], 'rows' => $meta['rows'], 'pruned' => count($pruned)]);
    echo json_encode(['name' => $meta['name'], 'size' => $meta['size'], 'rows' => $meta['rows'], 'pruned' => $pruned]), PHP_EOL;
} catch (Throwable $e) {
    Logger::log('error', 'backup : ' . $e->getMessage());
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
