<?php
declare(strict_types=1);

/**
 * Worker de rappels : planifie les rappels dus et envoie les notifications réservées.
 * Cron (chaque minute) :  * * * * * php /chemin/workers/reminder_worker.php
 * Options : --loop  répète toutes les 10 s pendant ~55 s (latence réduite, une seule entrée cron suffit).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\Notifications\Channels;
use App\Services\Notifications\NotificationEngine;

$workerId = substr(gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(2)), 0, 64);
$loop = in_array('--loop', $argv, true);
$deadline = time() + 55;
try {
    $pdo = Database::connection();
    $engine = new NotificationEngine($pdo, $workerId, Channels::senders($pdo)); // in-app + push/e-mail si configurés
    do {
        $stats = $engine->run();
        @touch((string) Config::get('app.paths.logs') . '/cron_heartbeat'); // l'installateur et la supervision s'y fient
        if (array_sum($stats) > 0) {
            Logger::log('info', 'reminder_worker', $stats + ['worker' => $workerId]);
        }
        echo json_encode($stats), PHP_EOL;
        if ($loop && time() + 10 < $deadline) {
            sleep(10);
        }
    } while ($loop && time() < $deadline - 5);
} catch (Throwable $e) {
    Logger::log('error', 'reminder_worker : ' . $e->getMessage(), ['worker' => $workerId]);
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
