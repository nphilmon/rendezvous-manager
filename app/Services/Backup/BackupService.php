<?php
declare(strict_types=1);

namespace App\Services\Backup;

use App\Core\HttpException;
use PDO;
use RuntimeException;

/**
 * Sauvegarde logique de la base en PHP pur (sans mysqldump) : un fichier .sql.gz (une instruction par ligne) + un fichier .json de contrôle
 * (somme SHA-256, tables, nombre de lignes). Instantané cohérent (transaction REPEATABLE READ). Les sessions et tentatives de connexion ne sont
 * pas sauvegardées (une restauration oblige à se reconnecter). La restauration est réservée à la ligne de commande (scripts/restore.php).
 * Le fichier contient toutes les données, y compris les condensats de mots de passe : le protéger comme la base elle-même.
 */
final class BackupService
{
    public const NAME = '/^rvm-\d{8}-\d{6}(-[a-f0-9]{6})?\.sql\.gz(\.enc)?$/';
    private const FORMAT = 'rvm-backup-1';
    private const END = '-- END rvm-backup-1';
    private const SKIP_DATA = ['user_sessions', 'login_attempts'];
    private const BATCH = 200;

    /** @param ?string $key clé maîtresse de 32 octets (BACKUP_KEY décodée) : si fournie, les nouvelles sauvegardes sont chiffrées */
    public function __construct(private PDO $pdo, private string $dir, private string $appVersion = '', private ?string $key = null)
    {
    }

    /** Service configuré depuis la configuration de l'application (dossier, version, BACKUP_KEY). Une BACKUP_KEY invalide provoque une exception : jamais de repli silencieux en clair. */
    public static function fromConfig(PDO $pdo): self
    {
        $k = (string) \App\Core\Config::get('app.backup.key', '');
        return new self($pdo, (string) \App\Core\Config::get('app.backup.dir'), (string) \App\Core\Config::get('app.version'), $k === '' ? null : BackupCrypto::parseKey($k));
    }

    public function encrypts(): bool
    {
        return $this->key !== null;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** @return array<string,mixed> métadonnées du nouveau fichier */
    public function create(?int $userId = null, ?\DateTimeImmutable $now = null): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Dossier de sauvegarde inaccessible.');
        }
        if (!is_writable($this->dir)) {
            throw new RuntimeException('Dossier de sauvegarde non inscriptible.');
        }
        $lock = $this->pdo->query("SELECT GET_LOCK('rvm_backup', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new HttpException(409, 'Une sauvegarde est déjà en cours.');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $name = 'rvm-' . $now->format('Ymd-His') . '.sql.gz';
        if (is_file($this->dir . '/' . $name)) {
            $name = 'rvm-' . $now->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz';
        }
        $part = $this->dir . '/' . $name . '.part';
        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Création du fichier impossible.');
        }
        $tables = [];
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        try {
            @chmod($part, 0600);
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $migration = (string) $this->pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            $w = static function (string $s) use ($gz): void {
                gzwrite($gz, $s . "\n");
            };
            $w('-- RendezVous Manager backup');
            $w('-- format: ' . self::FORMAT);
            $w('-- created: ' . $now->format('c'));
            $w('-- migration: ' . $migration);
            $w('SET NAMES utf8mb4;');
            $w('SET FOREIGN_KEY_CHECKS=0;');
            $w('SET UNIQUE_CHECKS=0;');
            $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");
            $names = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            sort($names);
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            foreach ($names as $t) {
                $q = '`' . str_replace('`', '``', $t) . '`';
                $create = (string) $this->pdo->query("SHOW CREATE TABLE $q")->fetchAll(PDO::FETCH_NUM)[0][1];
                $w("DROP TABLE IF EXISTS $q;");
                $w(rtrim(preg_replace('/\s*\n\s*/', ' ', $create) ?? $create) . ';');
                $count = 0;
                if (!in_array($t, self::SKIP_DATA, true)) {
                    $st = $this->pdo->query("SELECT * FROM $q");
                    $batch = [];
                    $cols = null;
                    while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                        $cols ??= '(' . implode(',', array_map(static fn ($i) => '`' . str_replace('`', '``', $st->getColumnMeta($i)['name']) . '`', array_keys($row))) . ')';
                        $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), $row)) . ')';
                        $count++;
                        if (count($batch) >= self::BATCH) {
                            $w("INSERT INTO $q $cols VALUES " . implode(',', $batch) . ';');
                            $batch = [];
                        }
                    }
                    $st->closeCursor();
                    if ($batch) {
                        $w("INSERT INTO $q $cols VALUES " . implode(',', $batch) . ';');
                    }
                }
                $tables[$t] = $count;
            }
            $w('SET FOREIGN_KEY_CHECKS=1;');
            $w(self::END);
            $this->pdo->exec('ROLLBACK');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            gzclose($gz);
            @unlink($part);
            throw $e;
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            $this->pdo->query("SELECT RELEASE_LOCK('rvm_backup')")->fetchColumn();
        }
        gzclose($gz);
        $final = $this->dir . '/' . $name;
        rename($part, $final);
        @chmod($final, 0600);
        if ($this->key !== null) {
            $enc = $final . '.enc';
            try {
                BackupCrypto::encryptFile($final, $enc . '.part', $this->key);
                rename($enc . '.part', $enc);
                @chmod($enc, 0600);
            } catch (\Throwable $e) {
                @unlink($enc . '.part');
                @unlink($final);
                throw $e;
            }
            unlink($final); // jamais de copie en clair à côté de la copie chiffrée
            $final = $enc;
            $name .= '.enc';
        }
        $meta = ['name' => $name, 'encrypted' => $this->key !== null, 'format' => self::FORMAT, 'created_at' => $now->format('Y-m-d\TH:i:s\Z'), 'created_by' => $userId, 'size' => filesize($final),
            'sha256' => hash_file('sha256', $final), 'migration' => $migration, 'app_version' => $this->appVersion, 'tables' => $tables, 'rows' => array_sum($tables)];
        file_put_contents($final . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($final . '.json', 0600);
        return $meta;
    }

    /** @return list<array<string,mixed>> du plus récent au plus ancien ; un fichier sans contrôle apparaît avec « sha256 » nul */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/rvm-*.sql.gz*') ?: [] as $f) {
            $name = basename($f);
            if (!preg_match(self::NAME, $name)) {
                continue;
            }
            $meta = is_file($f . '.json') ? (json_decode((string) file_get_contents($f . '.json'), true) ?: []) : [];
            $out[] = ['name' => $name, 'size' => (int) filesize($f), 'created_at' => $meta['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($f)), 'created_by' => $meta['created_by'] ?? null,
                'encrypted' => (bool) ($meta['encrypted'] ?? false), 'sha256' => $meta['sha256'] ?? null, 'rows' => $meta['rows'] ?? null, 'migration' => $meta['migration'] ?? null, 'tables' => isset($meta['tables']) ? count($meta['tables']) : null];
        }
        usort($out, static fn ($a, $b) => [$b['created_at'], $b['name']] <=> [$a['created_at'], $a['name']]);
        return $out;
    }

    /** Chemin d'un fichier de sauvegarde par son nom : le nom est strictement validé (aucun accès hors du dossier). */
    public function path(string $name): string
    {
        if (!preg_match(self::NAME, $name) || !is_file($this->dir . '/' . $name)) {
            throw new HttpException(404, 'Sauvegarde introuvable.');
        }
        return $this->dir . '/' . $name;
    }

    public function delete(string $name): void
    {
        $p = $this->path($name);
        @unlink($p . '.json');
        unlink($p);
    }

    /**
     * Contrôle d'intégrité : somme SHA-256 conforme au fichier de contrôle, archive gzip lisible jusqu'au bout et marqueur de fin présent.
     * @return array{ok:bool,problems:list<string>}
     */
    public function verify(string $path): array
    {
        $problems = [];
        if (!is_file($path)) {
            return ['ok' => false, 'problems' => ['Fichier introuvable.']];
        }
        if (is_file($path . '.json')) {
            $meta = json_decode((string) file_get_contents($path . '.json'), true) ?: [];
            if (($meta['sha256'] ?? '') !== hash_file('sha256', $path)) {
                $problems[] = 'La somme SHA-256 ne correspond pas : fichier altéré ou incomplet.';
            }
        } else {
            $problems[] = 'Fichier de contrôle (.json) absent : intégrité non vérifiable.';
        }
        $tmp = null;
        $read = $path;
        if (BackupCrypto::isEncrypted($path)) {
            try {
                [$read, $tmp] = $this->plainCopy($path);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'problems' => array_merge($problems, [$e->getMessage()])];
            }
        }
        $gz = @gzopen($read, 'rb');
        $last = '';
        $header = false;
        if ($gz === false) {
            $problems[] = 'Archive gzip illisible.';
        } else {
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\n");
                if ($line !== '') {
                    $last = $line;
                }
                $header = $header || $line === '-- format: ' . self::FORMAT;
            }
            if (!gzeof($gz)) {
                $problems[] = 'Archive gzip corrompue.';
            }
            gzclose($gz);
            if (!$header) {
                $problems[] = 'Format de sauvegarde non reconnu.';
            }
            if ($last !== self::END) {
                $problems[] = 'Marqueur de fin absent : sauvegarde incomplète.';
            }
        }
        if ($tmp !== null) {
            @unlink($tmp);
        }
        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * Fichier .sql.gz lisible : le fichier lui-même, ou une copie temporaire déchiffrée (à supprimer par l'appelant).
     * @return array{0:string,1:?string} [chemin à lire, chemin temporaire à supprimer]
     */
    private function plainCopy(string $path): array
    {
        if (!BackupCrypto::isEncrypted($path)) {
            return [$path, null];
        }
        if ($this->key === null) {
            throw new RuntimeException('Sauvegarde chiffrée : BACKUP_KEY absente de la configuration (php scripts/generate_backup_key.php, ou la clé d\'origine).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rvmdec');
        if ($tmp === false) {
            throw new RuntimeException('Fichier temporaire impossible.');
        }
        @chmod($tmp, 0600);
        try {
            BackupCrypto::decryptFile($path, $tmp, $this->key);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage());
        }
        return [$tmp, $tmp];
    }

    /**
     * Restaure le fichier (DROP + CREATE + INSERT des tables sauvegardées). Opération destructive : l'appelant doit avoir demandé confirmation.
     * Les tables absentes de la sauvegarde ne sont pas touchées. @return array{statements:int}
     */
    public function restore(string $path): array
    {
        $v = $this->verify($path);
        if (!$v['ok']) {
            throw new RuntimeException('Sauvegarde invalide : ' . implode(' ', $v['problems']));
        }
        [$read, $tmp] = $this->plainCopy($path);
        $gz = gzopen($read, 'rb');
        $n = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $this->pdo->exec($line);
                $n++;
            }
        } finally {
            gzclose($gz);
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        return ['statements' => $n];
    }

    /** Conserve les $keep sauvegardes les plus récentes (au moins une). @return list<string> noms supprimés */
    public function prune(int $keep): array
    {
        $keep = max(1, $keep);
        $gone = [];
        foreach (array_slice($this->list(), $keep) as $b) {
            $this->delete($b['name']);
            $gone[] = $b['name'];
        }
        return $gone;
    }
}
