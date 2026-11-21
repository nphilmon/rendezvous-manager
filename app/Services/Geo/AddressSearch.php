<?php
declare(strict_types=1);

namespace App\Services\Geo;

use App\Core\Env;

/**
 * Suggestions d'adresses via l'API Adresse (Base Adresse Nationale, service public français).
 * L'appel part du SERVEUR : le navigateur ne contacte jamais le service (la politique CSP reste « connect-src 'self' »), l'adresse IP
 * des utilisateurs n'est pas transmise, et seul le texte saisi dans le champ est envoyé. TLS vérifié, aucune redirection, délai court.
 */
final class AddressSearch
{
    public const DEFAULT_URL = 'https://api-adresse.data.gouv.fr/search/';

    /** @param ?\Closure(string):array{status:int,body:string} $http remplaçable dans les tests (aucun accès réseau) */
    public function __construct(private bool $enabled, private string $baseUrl, private ?\Closure $http = null, private int $timeout = 4)
    {
    }

    public static function fromConfig(): self
    {
        return new self(Env::bool('ADDRESS_API_ENABLED', true), Env::get('ADDRESS_API_URL', self::DEFAULT_URL) ?: self::DEFAULT_URL);
    }

    public function enabled(): bool
    {
        return $this->enabled && self::urlAllowed($this->baseUrl);
    }

    /** HTTPS obligatoire (http seulement vers localhost, pour les essais), sans identifiants intégrés. */
    public static function urlAllowed(string $u): bool
    {
        $p = parse_url($u);
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || ($p['host'] ?? '') === '') {
            return false;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        return $scheme === 'https' || ($scheme === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true));
    }

    /**
     * @return list<array{label:string,name:string,postcode:string,city:string}>
     * @throws AddressException service désactivé, injoignable ou réponse invalide
     */
    public function search(string $q): array
    {
        if (!$this->enabled()) {
            throw new AddressException('La recherche d\'adresses n\'est pas activée sur ce serveur.');
        }
        $url = $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . http_build_query(['q' => $q, 'limit' => 5, 'autocomplete' => 1]);
        $res = ($this->http ?? fn (string $u): array => $this->curl($u))($url);
        if (($res['status'] ?? 0) === 429) {
            throw new AddressException('Le service d\'adresses est momentanément saturé.');
        }
        if (($res['status'] ?? 0) !== 200) {
            throw new AddressException('Le service d\'adresses est injoignable.');
        }
        $j = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($j) || !isset($j['features']) || !is_array($j['features'])) {
            throw new AddressException('Réponse inattendue du service d\'adresses.');
        }
        $out = [];
        foreach ($j['features'] as $f) {
            $p = is_array($f) ? ($f['properties'] ?? null) : null;
            if (!is_array($p) || !is_string($p['label'] ?? null) || $p['label'] === '') {
                continue;
            }
            $s = static fn (string $k): string => self::clean(is_string($p[$k] ?? null) ? $p[$k] : '');
            $out[] = ['label' => self::clean($p['label']), 'name' => $s('name'), 'postcode' => $s('postcode'), 'city' => $s('city')];
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    /** Texte tiers : sans caractères de contrôle, longueur bornée. */
    private static function clean(string $v): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v)), 0, 255);
    }

    /** @return array{status:int,body:string} */
    private function curl(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeout), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_MAXFILESIZE => 131072,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'RendezVousManager']);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
