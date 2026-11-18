<?php
declare(strict_types=1);

/**
 * Worker des canaux « lents » (Web Push et e-mail) : envoie les notifications déjà planifiées, sans planifier ni toucher au canal
 * « dans l'application ». Utile pour qu'un service push lent ou un SMTP en panne ne retarde jamais les rappels internes ;
 * reminder_worker.php sait aussi tout faire seul (une seule entrée cron suffit pour de petites installations).
 * Cron (chaque minute) :  * * * * * php /chemin/workers/push_worker.php
 * Options : --loop  répète toutes les 10 s pendant ~55 s.
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

$workerId = substr('push-' . gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(2)), 0, 64);
$loop = in_array('--loop', $argv, true);
$deadline = time() + 55;
try {
    $pdo = Database::connection();
    $senders = Channels::senders($pdo, true);
    if (!$senders) {
        echo "Aucun canal push ou e-mail configuré.\n";
        exit(0);
    }
    $engine = new NotificationEngine($pdo, $workerId, $senders);
    do {
        $stats = $engine->run(null, 50, 20, false);
        @touch((string) Config::get('app.paths.logs') . '/cron_heartbeat_push');
        if (array_sum($stats) > 0) {
            Logger::log('info', 'push_worker', $stats + ['worker' => $workerId]);
        }
        echo json_encode($stats), PHP_EOL;
        if ($loop && time() + 10 < $deadline) {
            sleep(10);
        }
    } while ($loop && time() < $deadline - 5);
} catch (Throwable $e) {
    Logger::log('error', 'push_worker : ' . $e->getMessage(), ['worker' => $workerId]);
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
