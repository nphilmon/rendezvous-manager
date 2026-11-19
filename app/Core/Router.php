<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [strtoupper($method), $pattern, $handler];
    }

    /** @return list<array{0:string,1:string}> [méthode, motif] de chaque route (inventaire pour les tests de sécurité) */
    public function routes(): array
    {
        return array_map(static fn (array $r) => [$r[0], $r[1]], $this->routes);
    }

    public function dispatch(Request $req): Response
    {
        $methodMismatch = false;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req, $params);
        }
        throw $methodMismatch
            ? new HttpException(405, 'Méthode non autorisée.')
            : new HttpException(404, 'Ressource introuvable.');
    }
}
