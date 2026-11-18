<?php
declare(strict_types=1);

namespace App\Services\Push;

use App\Core\Config;
use RuntimeException;

/** Identification de l'application auprès des services push (RFC 8292, VAPID) : jeton JWT ES256 signé par le serveur. */
final class Vapid
{
    public function __construct(private string $publicB64, private string $privateB64, private string $subject)
    {
        if (!preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://\S+)$#', $subject)) {
            throw new RuntimeException('VAPID_SUBJECT doit être « mailto:adresse » ou une URL https.');
        }
    }

    /** @return self|null null si les clés ne sont pas configurées ou sont invalides */
    public static function fromConfig(): ?self
    {
        $pub = (string) Config::get('notifications.push.public_key', '');
        $priv = (string) Config::get('notifications.push.private_key', '');
        if ($pub === '' || $priv === '') {
            return null;
        }
        try {
            $v = new self($pub, $priv, (string) Config::get('notifications.push.subject', ''));
            $v->privateKey(); // valide la paire
            return $v;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{public:string,private:string} clés au format base64url (point non compressé ; scalaire de 32 octets) */
    public static function generate(): array
    {
        $k = EcKeys::generate();
        return ['public' => Base64Url::encode($k['public']), 'private' => Base64Url::encode($k['private'])];
    }

    public function publicKey(): string
    {
        return $this->publicB64;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $d = Base64Url::decode($this->privateB64);
        $p = Base64Url::decode($this->publicB64);
        if ($d === null || $p === null) {
            throw new RuntimeException('Clés VAPID illisibles.');
        }
        return EcKeys::privateFromScalar($d, $p);
    }

    /** JWT valable au plus 24 h (exigence des services push) pour l'origine du point d'accès. */
    public function jwt(string $endpoint, int $ttl = 43200, ?int $now = null): string
    {
        $u = parse_url($endpoint);
        if (!isset($u['scheme'], $u['host'])) {
            throw new RuntimeException('Point d\'accès invalide.');
        }
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $h = Base64Url::encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $c = Base64Url::encode(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + min($ttl, 86400), 'sub' => $this->subject], JSON_THROW_ON_ERROR));
        return $h . '.' . $c . '.' . Base64Url::encode(EcKeys::signEs256($h . '.' . $c, $this->privateKey()));
    }

    public function authorization(string $endpoint): string
    {
        return 'vapid t=' . $this->jwt($endpoint) . ', k=' . $this->publicB64;
    }

    /** Vérifie un jeton (service de simulation des tests). @return array<string,mixed>|null revendications si la signature est valide */
    public static function verify(string $jwt, string $publicB64): ?array
    {
        $p = explode('.', $jwt);
        $pub = Base64Url::decode($publicB64);
        $sig = isset($p[2]) ? Base64Url::decode($p[2]) : null;
        if (count($p) !== 3 || $pub === null || $sig === null) {
            return null;
        }
        if (!EcKeys::verifyEs256($p[0] . '.' . $p[1], $sig, EcKeys::publicFromPoint($pub))) {
            return null;
        }
        $claims = json_decode((string) Base64Url::decode($p[1]), true);
        return is_array($claims) ? $claims : null;
    }
}
