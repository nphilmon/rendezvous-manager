<?php
declare(strict_types=1);

use App\Controllers\PageController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Navigation;
use App\Services\PermissionService;

/** Routes des pages HTML. Chaque lien de la navigation a sa route ; les modules non livrés affichent une page explicite. */
return static function (Router $r): void {
    $page = static function (): PageController {
        $pdo = Database::connection();
        return new PageController(
            new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo)),
            new PermissionService($pdo),
            new StatsRepository($pdo),
        );
    };

    $r->add('GET', '/login', static fn (Request $q) => $page()->login($q));
    $r->add('GET', '/', static fn (Request $q) => $page()->dashboard($q));
    $r->add('GET', '/dashboard', static fn (Request $q) => $page()->dashboard($q));
    $r->add('GET', '/calendar', static fn (Request $q) => $page()->calendar($q));
    $r->add('GET', '/settings', static fn (Request $q) => $page()->settings($q));

    foreach (['all' => '/appointments', 'mine' => '/appointments/mine', 'today' => '/appointments/today', 'upcoming' => '/appointments/upcoming'] as $scope => $path) {
        $r->add('GET', $path, static fn (Request $q) => $page()->appointments($q, $scope));
    }
    $r->add('GET', '/appointments/new', static fn (Request $q) => $page()->appointments($q, 'all', true));

    foreach (['contacts', 'categories', 'calendars'] as $kind) {
        $r->add('GET', '/' . $kind, static fn (Request $q) => $page()->manage($q, $kind));
    }

    foreach (Navigation::items() as $key => $item) {
        if ($item['phase'] !== null) {
            $r->add('GET', $item['href'], static fn (Request $q) => $page()->module($q, $key, $item['label'], $item['phase']));
        }
    }
};
