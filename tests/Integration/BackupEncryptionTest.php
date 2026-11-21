<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupService;
use RuntimeException;
use Tests\DatabaseTestCase;

final class BackupEncryptionTest extends DatabaseTestCase
{
    private string $tmp;
    private string $key;

    public function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/rvm-enc-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->key = random_bytes(32);
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function svc(?string $key): BackupService
    {
        return new BackupService($this->pdo, $this->tmp, 'test', $key);
    }

    public function testChunkBoundariesRoundTripAndRandomisedOutput(): void
    {
        foreach ([0, 1, 1048575, 1048576, 1048577, 2 * 1048576, 2 * 1048576 + 5] as $size) {
            $plain = $this->tmp . '/p' . $size;
            $enc = $plain . '.enc';
            $out = $plain . '.out';
            file_put_contents($plain, $size > 0 ? random_bytes($size) : '');
            BackupCrypto::encryptFile($plain, $enc, $this->key);
            $this->assertTrue(BackupCrypto::isEncrypted($enc) && !BackupCrypto::isEncrypted($plain));
            BackupCrypto::decryptFile($enc, $out, $this->key);
            $this->assertTrue(file_get_contents($plain) === file_get_contents($out), "aller-retour de $size octets");
        }
        $a = $this->tmp . '/a';
        file_put_contents($a, str_repeat('même contenu', 1000));
        BackupCrypto::encryptFile($a, $a . '.1', $this->key);
        BackupCrypto::encryptFile($a, $a . '.2', $this->key);
        $this->assertTrue(file_get_contents($a . '.1') !== file_get_contents($a . '.2'), 'sel et nonce aléatoires : deux chiffrements différents');
    }

    public function testTamperingTruncationWrongKeyAndReorderingAreDetected(): void
    {
        $plain = $this->tmp . '/big';
        file_put_contents($plain, random_bytes(3 * 1048576 + 100));
        $enc = $plain . '.enc';
        BackupCrypto::encryptFile($plain, $enc, $this->key);
        $good = (string) file_get_contents($enc);
        $try = function (string $bytes, ?string $key = null) use ($plain): bool {
            file_put_contents($plain . '.bad', $bytes);
            try {
                BackupCrypto::decryptFile($plain . '.bad', $plain . '.out', $key ?? $this->key);
                return true;
            } catch (RuntimeException) {
                return false;
            }
        };
        $this->assertTrue($try($good), 'fichier intact');
        $flip = $good;
        $flip[500000] = $flip[500000] ^ "\x01";
        $this->assertTrue(!$try($flip), 'un bit modifié');
        $this->assertTrue(!$try(substr($good, 0, 40)), 'tronqué dans le premier bloc');
        $this->assertTrue(!$try(substr($good, 0, 31 + 4 + 1048576 + 16)), 'tronqué EXACTEMENT après un bloc : détecté grâce au drapeau « dernier bloc »');
        $this->assertTrue(!$try($good . "\0\0\0"), 'octets ajoutés');
        $this->assertTrue(!$try($good, random_bytes(32)), 'mauvaise clé');
        $c = 31 + 4 + 1048576 + 16; // un bloc complet
        $this->assertTrue(!$try(substr($good, 0, 31) . substr($good, $c, $c) . substr($good, 31, $c) . substr($good, 31 + 2 * $c)), 'blocs permutés');
        $hdr = $good;
        $hdr[10] = $hdr[10] ^ "\x01";
        $this->assertTrue(!$try($hdr), 'en-tête modifié (sel)');
        $this->assertTrue(!$try('RVMENC1'), 'en-tête incomplet');
        $this->assertTrue(!$try('pas une sauvegarde'), 'format inconnu');
    }

    public function testEncryptedBackupHidesTheDataAndRestoresIt(): void
    {
        $this->pdo->exec("INSERT INTO roles (id, code, label) VALUES (90, 'secretrole', 'Libellé très confidentiel XYZ') ON DUPLICATE KEY UPDATE label = label");
        $meta = $this->svc($this->key)->create(1);
        $this->assertTrue(str_ends_with($meta['name'], '.sql.gz.enc') && $meta['encrypted'] === true, 'extension .enc');
        $this->assertSame([$meta['name'], $meta['name'] . '.json'], array_map('basename', glob($this->tmp . '/*')) , 'aucune copie en clair à côté');
        $raw = (string) file_get_contents($this->tmp . '/' . $meta['name']);
        $this->assertTrue(!str_contains($raw, 'CREATE TABLE') && !str_contains($raw, 'confidentiel') && !str_starts_with($raw, "\x1f\x8b"), 'le contenu n\'apparaît pas');
        $this->assertSame(hash('sha256', $raw), $meta['sha256']);
        $this->assertSame(0600, fileperms($this->tmp . '/' . $meta['name']) & 0777);
        $this->assertSame(['ok' => true, 'problems' => []], $this->svc($this->key)->verify($this->svc($this->key)->path($meta['name'])));
        // sans la clé ou avec une autre : lisible ni vérifiable ni restaurable
        $path = $this->svc($this->key)->path($meta['name']);
        $nokey = $this->svc(null)->verify($path);
        $this->assertTrue(!$nokey['ok'] && str_contains(implode(' ', $nokey['problems']), 'BACKUP_KEY'), 'clé absente signalée');
        $this->assertTrue(!$this->svc(random_bytes(32))->verify($path)['ok'], 'mauvaise clé');
        // restauration
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec("DELETE FROM roles WHERE code = 'secretrole'");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->assertThrowsRuntime(fn () => $this->svc(null)->restore($path), 'restauration refusée sans clé');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM roles WHERE code = 'secretrole'")->fetchColumn());
        $this->svc($this->key)->restore($path);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM roles WHERE code = 'secretrole'")->fetchColumn(), 'restaurée avec la clé');
        $this->assertSame([], glob(sys_get_temp_dir() . '/rvmdec*') ?: [], 'aucun fichier déchiffré ne traîne dans le dossier temporaire');
    }

    public function testPlainAndEncryptedBackupsCoexistAndKeyValidation(): void
    {
        $plain = $this->svc(null)->create(null, new \DateTimeImmutable('2026-01-01 03:00:00', new \DateTimeZone('UTC')));
        $enc = $this->svc($this->key)->create(null, new \DateTimeImmutable('2026-01-02 03:00:00', new \DateTimeZone('UTC')));
        $list = $this->svc($this->key)->list();
        $this->assertSame([[$enc['name'], true], [$plain['name'], false]], array_map(fn ($b) => [$b['name'], $b['encrypted']], $list));
        $this->assertTrue($this->svc($this->key)->verify($this->svc($this->key)->path($plain['name']))['ok'], 'les anciennes sauvegardes en clair restent vérifiables');
        $this->svc($this->key)->delete($enc['name']);
        $this->assertSame([$plain['name']], array_column($this->svc(null)->list(), 'name'), 'suppression : fichier chiffré et contrôle');
        foreach (['', 'pas-du-base64!!', base64_encode('trop court'), base64_encode(random_bytes(31)), base64_encode(random_bytes(33))] as $bad) {
            $this->assertThrowsRuntime(fn () => BackupCrypto::parseKey($bad), "clé invalide : $bad");
        }
        $this->assertSame(32, strlen(BackupCrypto::parseKey(base64_encode(random_bytes(32)))));
    }

    private function assertThrowsRuntime(callable $f, string $msg): void
    {
        try {
            $f();
        } catch (RuntimeException) {
            return;
        }
        $this->assertTrue(false, "exception attendue : $msg");
    }
}
