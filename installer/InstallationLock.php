<?php
declare(strict_types=1);

namespace Installer;

/**
 * Verrou d'installation et jeton d'accès à usage unique.
 * Le jeton est généré uniquement en ligne de commande (scripts/install_token.php) :
 * il n'est jamais affiché par le serveur web. Seul son hachage est stocké.
 */
final class InstallationLock
{
    private const TOKEN_TTL = 7200;
    private const MAX_ATTEMPTS = 10;

    public function __construct(private string $lockFile, private string $tokenFile)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile);
    }

    public function lock(): void
    {
        $this->atomicWrite($this->lockFile, json_encode(['installed_at' => gmdate('c')], JSON_THROW_ON_ERROR));
        $this->revokeToken();
    }

    /** @return string le jeton en clair (à afficher une seule fois, en CLI) */
    public function issueToken(): string
    {
        if ($this->isInstalled()) {
            throw new \RuntimeException('La plateforme est déjà installée.');
        }
        $token = implode('-', str_split(bin2hex(random_bytes(12)), 6));
        $this->atomicWrite($this->tokenFile, json_encode([
            'hash' => hash('sha256', $token), 'expires' => time() + self::TOKEN_TTL, 'attempts' => 0,
        ], JSON_THROW_ON_ERROR));
        return $token;
    }

    public function hasValidToken(): bool
    {
        $d = $this->readToken();
        return $d !== null && $d['expires'] > time();
    }

    /** Vérifie le jeton ; en cas de succès il est consommé (usage unique). */
    public function consume(string $candidate): bool
    {
        if ($this->isInstalled()) {
            return false;
        }
        $d = $this->readToken();
        if ($d === null || $d['expires'] <= time()) {
            return false;
        }
        if (hash_equals($d['hash'], hash('sha256', trim($candidate)))) {
            $this->revokeToken();
            return true;
        }
        $d['attempts']++;
        if ($d['attempts'] >= self::MAX_ATTEMPTS) {
            $this->revokeToken(); // trop d'essais : il faut en régénérer un
        } else {
            $this->atomicWrite($this->tokenFile, json_encode($d, JSON_THROW_ON_ERROR));
        }
        usleep(300000);
        return false;
    }

    public function revokeToken(): void
    {
        if (is_file($this->tokenFile)) {
            @unlink($this->tokenFile);
        }
    }

    /** @return array{hash:string,expires:int,attempts:int}|null */
    private function readToken(): ?array
    {
        if (!is_file($this->tokenFile)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($this->tokenFile), true);
        return is_array($d) && isset($d['hash'], $d['expires']) ? $d + ['attempts' => 0] : null;
    }

    private function atomicWrite(string $file, string $content): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException("Écriture impossible : $file");
        }
        chmod($tmp, 0600);
        rename($tmp, $file);
    }
}
