<?php
declare(strict_types=1);

// Génère la clé de chiffrement des sauvegardes (AES-256-GCM) et l'écrit dans .env (droits 0600). Usage : php scripts/generate_backup_key.php [--force]
// ATTENTION : la clé n'est affichée qu'une fois. SANS ELLE, LES SAUVEGARDES CHIFFRÉES SONT ILLISIBLES : copiez-la dans un gestionnaire de mots de passe,
// hors du serveur. Changer la clé (--force) ne rend pas illisibles les sauvegardes déjà faites si vous conservez l'ancienne clé : gardez-les toutes.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Env;

$force = in_array('--force', $argv, true);
$file = dirname(__DIR__) . '/.env';
$env = is_file($file) ? Env::parse((string) file_get_contents($file)) : [];
if (($env['BACKUP_KEY'] ?? '') !== '' && !$force) {
    fwrite(STDERR, "Une clé existe déjà dans .env. --force la remplace : les sauvegardes déjà chiffrées ne se déchiffreront plus qu'avec l'ancienne clé (conservez-la).\n");
    exit(1);
}
$key = base64_encode(random_bytes(32));
$lines = is_file($file) ? (preg_split('/\R/', rtrim((string) file_get_contents($file))) ?: []) : [];
$done = false;
foreach ($lines as $i => $l) {
    if (!str_starts_with(trim($l), '#') && trim(explode('=', $l, 2)[0]) === 'BACKUP_KEY') {
        $lines[$i] = 'BACKUP_KEY=' . $key;
        $done = true;
    }
}
if (!$done) {
    $lines[] = 'BACKUP_KEY=' . $key;
}
$tmp = $file . '.tmp';
file_put_contents($tmp, implode("\n", $lines) . "\n");
chmod($tmp, 0600);
rename($tmp, $file);
echo "Clé de chiffrement enregistrée dans .env (droits 0600).\n\n  BACKUP_KEY=$key\n\n";
echo "COPIEZ-LA MAINTENANT hors du serveur (gestionnaire de mots de passe) : elle ne sera plus affichée.\n";
echo "Les prochaines sauvegardes seront chiffrées (.sql.gz.enc) ; les anciennes restent lisibles telles quelles.\n";
