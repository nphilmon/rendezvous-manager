<?php
declare(strict_types=1);

// Contrôle de santé pour la supervision (code de sortie 0 = tout est conforme, 1 = anomalie). Usage : php scripts/healthcheck.php [--json]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Services\Mail\SmtpMailer;
use App\Services\Push\Vapid;

$checks = [];
$add = function (string $name, bool $ok, string $msg, bool $required = true) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'required' => $required, 'message' => $msg];
};
try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1');
    $add('database', true, 'Connexion à la base réussie');
    $pending = (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->pending();
    $add('migrations', $pending === [], $pending === [] ? 'Schéma à jour' : 'Migrations en attente : ' . implode(', ', $pending));
} catch (Throwable $e) {
    $add('database', false, 'Base inaccessible');
}
$logs = (string) Config::get('app.paths.logs');
$hb = $logs . '/cron_heartbeat';
$age = is_file($hb) ? time() - (int) filemtime($hb) : null;
$add('reminder_worker', $age !== null && $age < 300, $age === null ? 'Aucun passage enregistré (cron non configuré ?)' : "Dernier passage il y a {$age} s");
$add('logs', is_dir($logs) && is_writable($logs), 'Journaux inscriptibles');
$add('push', Vapid::fromConfig() !== null, Vapid::fromConfig() !== null ? 'Clés VAPID valides' : 'Web Push non configuré', false);
$add('email', SmtpMailer::fromConfig() !== null, SmtpMailer::fromConfig() !== null ? 'SMTP configuré' : 'E-mail non configuré', false);
$ok = true;
foreach ($checks as $c) {
    $ok = $ok && ($c['ok'] || !$c['required']);
}
if (in_array('--json', $argv, true)) {
    echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} else {
    foreach ($checks as $c) {
        printf("[%s] %s : %s\n", $c['ok'] ? 'OK' : ($c['required'] ? 'ÉCHEC' : 'absent'), $c['name'], $c['message']);
    }
}
exit($ok ? 0 : 1);
