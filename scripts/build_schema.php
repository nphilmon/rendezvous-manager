<?php
declare(strict_types=1);

// Régénère database/schema.sql (instantané complet) à partir des migrations.
$dir = dirname(__DIR__) . '/database';
$out = "-- RendezVous Manager : schéma complet (généré par scripts/build_schema.php, ne pas modifier à la main)\n"
     . "-- Pour une installation normale, utiliser scripts/migrate.php.\n\n";
foreach (glob($dir . '/migrations/*.sql') ?: [] as $f) {
    $out .= '-- ' . basename($f) . "\n" . trim((string) file_get_contents($f)) . "\n\n";
}
file_put_contents($dir . '/schema.sql', $out);
echo "schema.sql généré.\n";
