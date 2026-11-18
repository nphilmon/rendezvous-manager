<?php
declare(strict_types=1);

// Serveur de développement PHP : laisser servir les fichiers statiques existants.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($f) && !str_ends_with($f, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PageController;
use App\Core\Application;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Core\Request;
use App\Core\Router;

$deps = static function (): array {
    $pdo = Database::connection();
    return [new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo)), new PermissionService($pdo), new StatsRepository($pdo)];
};
$router = new Router();
(require dirname(__DIR__) . '/api/routes.php')($router);
(require dirname(__DIR__) . '/app/web.php')($router);

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
    try {
        $router->dispatch($req)->send();
    } catch (HttpException $e) {
        // 404/405 : page d'erreur HTML ; l'utilisateur voit le gabarit s'il est connecté.
        (new PageController(...$deps()))->notFound($req)->send();
    } catch (\Throwable $e) {
        Logger::log('error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo Config::get('app.debug') ? $e->getMessage() : "Erreur interne du serveur.\n";
    }
    exit;
}
Application::handle($req, $router)->send();
