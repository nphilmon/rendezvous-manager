<?php
declare(strict_types=1);

// Génère une paire de clés VAPID et l'écrit dans .env (permissions 0600). Usage : php scripts/generate_vapid.php [--force] [--subject=mailto:admin@exemple.org]
// ATTENTION : changer la paire invalide tous les abonnements push existants (les navigateurs doivent se réabonner).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Env;
use App\Services\Push\Vapid;

$force = in_array('--force', $argv, true);
$subject = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--subject=')) {
        $subject = substr($a, 10);
    }
}
$file = dirname(__DIR__) . '/.env';
$env = is_file($file) ? Env::parse((string) file_get_contents($file)) : [];
if (!empty($env['VAPID_PRIVATE_KEY']) && !$force) {
    fwrite(STDERR, "Des clés VAPID existent déjà : --force les remplace (tous les abonnements push seront perdus).\n");
    exit(1);
}
$subject ??= ($env['VAPID_SUBJECT'] ?? '') !== '' ? $env['VAPID_SUBJECT'] : null;
if ($subject === null || !preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://\S+)$#', $subject)) {
    fwrite(STDERR, "Indiquez un contact : --subject=mailto:admin@exemple.org (ou une URL https).\n");
    exit(1);
}
$k = Vapid::generate();
$new = ['VAPID_PUBLIC_KEY' => $k['public'], 'VAPID_PRIVATE_KEY' => $k['private'], 'VAPID_SUBJECT' => $subject];
$lines = is_file($file) ? (preg_split('/\R/', rtrim((string) file_get_contents($file))) ?: []) : [];
$seen = [];
foreach ($lines as $i => $l) {
    $key = trim(explode('=', $l, 2)[0]);
    if (isset($new[$key]) && !str_starts_with(trim($l), '#')) {
        $lines[$i] = $key . '=' . $new[$key];
        $seen[$key] = true;
    }
}
foreach ($new as $key => $v) {
    if (!isset($seen[$key])) {
        $lines[] = $key . '=' . $v;
    }
}
$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
file_put_contents($tmp, implode("\n", $lines) . "\n");
chmod($tmp, 0600);
rename($tmp, $file);
echo "Clés VAPID écrites dans .env (permissions 0600).\nClé publique : {$k['public']}\n";
