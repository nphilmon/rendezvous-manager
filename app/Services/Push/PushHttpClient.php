<?php
declare(strict_types=1);

namespace App\Services\Push;

interface PushHttpClient
{
    /**
     * @param array<string,string> $headers
     * @param string|null $pinIp adresse IP validée à utiliser pour joindre l'hôte (évite une nouvelle résolution DNS)
     * @return array{status:int,body:string,error:?string} status 0 en cas d'échec réseau
     */
    public function post(string $url, array $headers, string $body, int $timeout, ?string $pinIp = null): array;
}
