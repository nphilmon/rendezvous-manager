<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;

$router = new Router();
(require dirname(__DIR__) . '/api/routes.php')($router);

$req = Request::fromGlobals();

use Installer\DatabaseSetup;
use Installer\InstallationLock;
use Installer\InstallerController;
use Installer\NativeSessionStore;
use Installer\PrerequisiteChecker;

$root = dirname(__DIR__);
$lock = new InstallationLock($root . '/storage/install.lock', $root . '/storage/install.token');
if ($req->path === '/install' || str_starts_with($req->path, '/install/')) {
    (new InstallerController(
        $lock,
        new DatabaseSetup($root . '/.env', $root . '/database/migrations'),
        new PrerequisiteChecker($root, $req->https),
        new NativeSessionStore($root . '/storage/sessions', $req->https),
        $root . '/installer/views',
    ))->handle($req)->send();
    exit;
}
if (!$lock->isInstalled() && !str_starts_with($req->path, '/api/')) {
    header('Location: /install', true, 302);
    exit;
}
if (!str_starts_with($req->path, '/api/')) {
    // L'interface web (installateur, connexion, calendrier) arrive dans les phases suivantes.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "RendezVous Manager : interface non encore disponible.\n";
    exit;
}
Application::handle($req, $router)->send();
