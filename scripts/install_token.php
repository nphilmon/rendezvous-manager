<?php
declare(strict_types=1);

// Génère un jeton d'installation à usage unique (valable 2 h). À lancer sur le serveur uniquement.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use Installer\InstallationLock;

$root = dirname(__DIR__);
$lock = new InstallationLock($root . '/storage/install.lock', $root . '/storage/install.token');
try {
    $token = $lock->issueToken();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
echo "Jeton d'installation (affiché une seule fois, valable 2 h, usage unique) :\n\n  $token\n\n";
echo "Ouvrez ensuite https://<votre-domaine>/install\n";
