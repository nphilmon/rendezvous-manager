<?php
declare(strict_types=1);

// Contrôle de déploiement. Usage : php scripts/deploy_check.php [--url=https://votre-domaine] [--json]
//   sans --url : contrôles locaux (PHP, .env, droits, base, cron, sauvegardes) ; avec --url : + exposition depuis Internet (fichiers sensibles, HTTPS, en-têtes).
// Code de sortie 1 s'il y a au moins un « ÉCHEC ».
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\DeployCheck;

$root = dirname(__DIR__);
$env = [];
foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'COOKIE_SECURE', 'HSTS_MAX_AGE', 'BACKUP_DIR'] as $k) {
    $v = \App\Core\Env::get($k, '');
    if ($v !== '') {
        $env[$k] = (string) $v;
    }
}
$url = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--url=')) {
        $url = substr($a, 6);
    }
}
try {
    $pdo = Database::connection();
} catch (Throwable) {
    $pdo = null;
}
$check = new DeployCheck($root, $env);
$results = $check->local($pdo ?? null);
if ($pdo === null) {
    $results[] = ['id' => 'database', 'level' => 'fail', 'message' => 'Base inaccessible'];
}
if ($url !== '') {
    $results = array_merge($results, $check->remote($url));
}
if (in_array('--json', $argv, true)) {
    echo json_encode(['ok' => DeployCheck::exitCode($results) === 0, 'checks' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
} else {
    $label = ['ok' => ' OK  ', 'warn' => 'AVERT', 'fail' => 'ÉCHEC'];
    foreach ($results as $x) {
        printf("[%s] %s\n", $label[$x['level']], $x['message']);
    }
    $n = array_count_values(array_column($results, 'level')) + ['ok' => 0, 'warn' => 0, 'fail' => 0];
    printf("\n%d conforme(s), %d avertissement(s), %d échec(s)\n", $n['ok'], $n['warn'], $n['fail']);
}
exit(DeployCheck::exitCode($results));
