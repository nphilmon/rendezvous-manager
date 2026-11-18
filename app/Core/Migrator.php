<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Migrations SQL versionnées (fichiers NNNN_nom.sql), appliquées une seule fois,
 * avec somme de contrôle : une migration déjà appliquée puis modifiée est refusée.
 * Note : le DDL MySQL/MariaDB provoque un commit implicite ; une migration en échec
 * peut donc être partiellement appliquée et doit être corrigée manuellement.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               version VARCHAR(20) NOT NULL,
               name VARCHAR(190) NOT NULL,
               checksum CHAR(64) NOT NULL,
               applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,array{name:string,file:string,checksum:string}> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.sql$/', basename($file), $m)) {
                continue;
            }
            $out[$m[1]] = ['name' => $m[2], 'file' => $file, 'checksum' => hash_file('sha256', $file)];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,string> version => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        return $this->pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return list<string> versions en attente */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->available()), array_keys($this->applied())));
    }

    /** @return list<string> versions appliquées par cet appel */
    public function migrate(): array
    {
        $applied = $this->applied();
        $done = [];
        foreach ($this->available() as $version => $m) {
            if (isset($applied[$version])) {
                if ($applied[$version] !== $m['checksum']) {
                    throw new RuntimeException("La migration $version a été modifiée après application.");
                }
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($m['file'])) as $sql) {
                $this->pdo->exec($sql);
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (version, name, checksum) VALUES (?, ?, ?)')
                ->execute([$version, $m['name'], $m['checksum']]);
            $done[] = $version;
        }
        return $done;
    }

    /** @return list<string> Découpe sur les ";" de fin de ligne (hors commentaires --). */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $buf .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $out[] = trim($buf);
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
