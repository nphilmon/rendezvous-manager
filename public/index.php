<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;

$router = new Router();
(require dirname(__DIR__) . '/api/routes.php')($router);

$req = Request::fromGlobals();
if (!str_starts_with($req->path, '/api/')) {
    // L'interface web (installateur, connexion, calendrier) arrive dans les phases suivantes.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "RendezVous Manager : interface non encore disponible.\n";
    exit;
}
Application::handle($req, $router)->send();
