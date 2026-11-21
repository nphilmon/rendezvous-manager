<?php
declare(strict_types=1);

// Restauration d'une sauvegarde (ligne de commande uniquement). OPÉRATION DESTRUCTIVE : remplace les tables contenues dans la sauvegarde.
// Usage : php scripts/restore.php <nom|chemin> [--yes] [--no-safety] [--database=AUTRE_BASE]
//   --yes        confirmation obligatoire ; sans elle, le fichier est seulement vérifié (rien n'est modifié)
//   --no-safety  ne crée pas la sauvegarde de sécurité de l'état actuel avant de restaurer
//   --database=  restaure dans UNE AUTRE base (test de restauration, sans toucher à la production) : l'utilisateur SQL doit avoir accès à cette base ;
//                aucune sauvegarde de sécurité n'est alors nécessaire. Sauvegardes chiffrées : BACKUP_KEY doit être présente dans .env.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\Database;
use App\Services\AuditLogger;
use App\Services\Backup\BackupService;

$args = array_slice($argv, 1);
$yes = in_array('--yes', $args, true);
$safety = !in_array('--no-safety', $args, true);
$file = current(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));
$other = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--database=')) {
        $other = substr($a, 11);
        if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $other)) {
            fwrite(STDERR, "Nom de base invalide.\n");
            exit(2);
        }
        Env::set('DB_NAME', $other); // avant toute connexion
        $safety = false;
    }
}
if (!$file) {
    fwrite(STDERR, "Usage : php scripts/restore.php <nom|chemin> --yes [--no-safety]\n");
    exit(2);
}
try {
    $pdo = Database::connection();
    $svc = BackupService::fromConfig($pdo);
    $path = is_file($file) ? $file : $svc->path(basename($file));
    $v = $svc->verify($path);
    if (!$v['ok']) {
        fwrite(STDERR, "Sauvegarde invalide :\n - " . implode("\n - ", $v['problems']) . "\n");
        exit(1);
    }
    echo "Sauvegarde valide : $path\n";
    if ($other !== null) {
        echo "Base cible : $other (test de restauration, la base de production n'est pas touchée).\n";
    }
    if (!$yes) {
        echo "Rien n'a été modifié. Ajoutez --yes pour restaurer (les tables de la sauvegarde seront remplacées).\n";
        exit(0);
    }
    if ($safety) {
        $s = $svc->create(null);
        echo "Sauvegarde de sécurité de l'état actuel : {$s['name']}\n";
    }
    $r = $svc->restore($path);
    // la table audit_logs peut avoir été remplacée : on trace la restauration dans la base restaurée
    (new AuditLogger($pdo))->log('backup.restore', null, null, null, 'cli', ['name' => basename($path)]);
    echo "Restauration terminée ({$r['statements']} instructions). Les sessions ne sont pas restaurées : reconnectez-vous.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n");
    exit(1);
}
