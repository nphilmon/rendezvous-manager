<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** Routes de l'API REST v1. Les routes métier sont ajoutées par phase. */
return static function (Router $r): void {
    $r->add('GET', '/api/v1/health', static function (Request $req): Response {
        $db = 'ok';
        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable) {
            $db = 'unavailable'; // détail volontairement non exposé
        }
        $ok = $db === 'ok';
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'database' => $db], $ok ? 200 : 503);
    });

    $r->add('GET', '/api/v1/version', static fn (): Response => Response::json([
        'name' => Config::get('app.name'),
        'version' => Config::get('app.version'),
    ]));
};
