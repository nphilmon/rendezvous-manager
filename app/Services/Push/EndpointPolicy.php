<?php
declare(strict_types=1);

namespace App\Services\Push;

use App\Core\Config;

/**
 * Le serveur appelle l'URL fournie par le navigateur : sans garde-fou, ce serait une faille SSRF.
 * Règles : https obligatoire ; hôte appartenant à la liste des services push connus (configurable) ;
 * toutes les adresses IP résolues doivent être publiques ; l'IP validée est épinglée pour l'appel (anti « DNS rebinding »).
 * allowAny/allowInsecure ne servent qu'aux tests et au développement.
 */
final class EndpointPolicy
{
    /** @param list<string> $allowedHosts @param (callable(string):list<string>)|null $resolver */
    public function __construct(
        private array $allowedHosts,
        private bool $allowAny = false,
        private bool $allowInsecure = false,
        private $resolver = null,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((array) Config::get('notifications.push.allowed_hosts', []), (bool) Config::get('notifications.push.allow_any', false), (bool) Config::get('notifications.push.allow_any', false));
    }

    /** @return array{ok:bool,error:?string,host:string,port:int,ip:?string} */
    public function check(string $endpoint): array
    {
        $fail = static fn (string $m) => ['ok' => false, 'error' => $m, 'host' => '', 'port' => 0, 'ip' => null];
        if (strlen($endpoint) > 2000 || preg_match('/[\x00-\x20\x7f]/', $endpoint)) {
            return $fail('Point d\'accès invalide.');
        }
        $u = parse_url($endpoint);
        if (!$u || !isset($u['scheme'], $u['host']) || isset($u['user']) || isset($u['pass'])) {
            return $fail('Point d\'accès invalide.');
        }
        $scheme = strtolower($u['scheme']);
        $host = strtolower($u['host']);
        if ($scheme !== 'https' && !($this->allowInsecure && $scheme === 'http')) {
            return $fail('Le point d\'accès doit utiliser https.');
        }
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!$this->allowAny) {
            $ok = false;
            foreach ($this->allowedHosts as $h) {
                if ($host === $h || str_ends_with($host, '.' . $h)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return $fail('Service push non reconnu : ' . $host . '.');
            }
        }
        $ips = $this->resolve($host);
        if (!$ips) {
            return $fail('Nom d\'hôte introuvable.');
        }
        if (!$this->allowAny) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $fail('Adresse non publique refusée.');
                }
            }
        }
        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => (int) $port, 'ip' => $ips[0]];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return [trim($host, '[]')];
        }
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        if (!$ips) {
            $a = @gethostbynamel($host);
            $ips = $a ?: [];
        }
        return array_values(array_filter($ips));
    }
}
