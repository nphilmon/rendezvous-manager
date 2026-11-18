<?php
declare(strict_types=1);

// Usage : php scripts/migrate.php [--status]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Migrator;

try {
    $m = new Migrator(Database::connection(), dirname(__DIR__) . '/database/migrations');
    if (in_array('--status', $argv, true)) {
        $applied = $m->applied();
        foreach ($m->available() as $v => $info) {
            echo ($applied[$v] ?? null) ? '[appliquée] ' : '[en attente] ', $v, ' ', $info['name'], PHP_EOL;
        }
        exit(0);
    }
    $done = $m->migrate();
    echo $done ? 'Migrations appliquées : ' . implode(', ', $done) : 'Base déjà à jour.', PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
