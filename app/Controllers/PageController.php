<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AuthUser;
use App\Repositories\StatsRepository;
use App\Services\DashboardService;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncRepository;
use App\Services\AuthService;
use App\Services\Navigation;
use App\Services\PermissionService;

/** Pages HTML (interface web). Les données viennent exclusivement de la base et des permissions de l'utilisateur. */
final class PageController
{
    public const STATUS_LABELS = ['scheduled' => 'Planifié', 'confirmed' => 'Confirmé', 'tentative' => 'Provisoire', 'cancelled' => 'Annulé', 'completed' => 'Terminé'];
    public const PRIORITY_LABELS = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
    public const VIEWS = ['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'agenda' => 'Planning'];

    public function __construct(
        private AuthService $auth,
        private PermissionService $perms,
        private StatsRepository $stats,
        private ?SyncRepository $sync = null,
        private ?SettingsRepository $settings = null,
    ) {
    }

    /** Accepte uniquement un chemin local (anti redirection ouverte). */
    public static function safeNext(?string $next): string
    {
        if ($next === null || $next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')
            || preg_match('/[\x00-\x1f]/', $next) || str_starts_with($next, '/api/') || str_starts_with($next, '/install')) {
            return '/';
        }
        return $next;
    }

    /** @return array{user:AuthUser,csrf:string,session_id:string}|null */
    private function session(Request $req): ?array
    {
        return $this->auth->authenticate($req->cookie((string) Config::get('app.auth.cookie_name', 'rvm_session')));
    }

    public function login(Request $req): Response
    {
        $next = self::safeNext($req->query['next'] ?? null);
        if ($this->session($req) !== null) {
            return Response::redirect($next);
        }
        return Response::html(View::render('login', ['next' => $next, 'title' => 'Connexion', 'appName' => Config::get('app.name')], 'bare'));
    }

    public function dashboard(Request $req): Response
    {
        return $this->page($req, 'dashboard', 'Tableau de bord', fn (AuthUser $u): array => ['calendarCount' => count($this->perms->readableCalendarIds($u)),
            'periods' => DashboardService::PERIODS, 'canCreate' => $u->can('appointments.write')], 'pages/dashboard', ['dashboard' => true]);
    }

    public function calendar(Request $req): Response
    {
        $view = $req->query['view'] ?? 'week';
        $view = is_string($view) && isset(self::VIEWS[$view]) ? $view : 'week';
        $date = $req->query['date'] ?? '';
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) ? $date : '';
        return $this->page($req, 'calendar', 'Calendrier', fn (AuthUser $u) => ['view' => $view, 'date' => $date, 'views' => self::VIEWS, 'tz' => $u->timezone, 'canCreate' => $u->can('appointments.write')], 'pages/calendar',
            ['calendarNav' => true, 'view' => $view, 'date' => $date]);
    }

    /** Liste des rendez-vous (filtres, tri, pagination côté API). $scope : all | mine | today | upcoming. */
    public function appointments(Request $req, string $scope, bool $autoNew = false): Response
    {
        $titles = ['all' => 'Tous les rendez-vous', 'mine' => 'Mes rendez-vous', 'today' => 'Aujourd\'hui', 'upcoming' => 'À venir'];
        return $this->page($req, $scope, $titles[$scope], fn (AuthUser $u) => ['scope' => $scope, 'autoNew' => $autoNew,
            'heading' => $titles[$scope], 'statuses' => self::STATUS_LABELS, 'priorities' => self::PRIORITY_LABELS], 'pages/appointments');
    }

    /** Gestion des contacts, catégories et calendriers (interface pilotée par manage.js). */
    public function manage(Request $req, string $kind): Response
    {
        $titles = ['contacts' => 'Contacts', 'categories' => 'Catégories', 'calendars' => 'Calendriers', 'notifications' => 'Notifications'];
        $write = ['contacts' => 'contacts.write', 'categories' => 'categories.manage', 'calendars' => 'calendars.create', 'notifications' => 'appointments.read'];
        return $this->page($req, $kind, $titles[$kind], fn (AuthUser $u) => ['kind' => $kind, 'heading' => $titles[$kind], 'canWrite' => $u->can($write[$kind]),
            'canCreate' => $u->can('appointments.write')], 'pages/manage', ['manage' => true]);
    }

    /** Journal d'audit (history.js), droit « audit.view ». */
    public function history(Request $req): Response
    {
        return $this->page($req, 'history', 'Historique', fn (AuthUser $u) => [], 'pages/history', ['historyPage' => true]);
    }

    /** Sauvegardes (backups.js), droit « backups.manage ». */
    public function backups(Request $req): Response
    {
        return $this->page($req, 'backups', 'Sauvegardes', fn (AuthUser $u) => ['keep' => (int) Config::get('app.backup.keep', 14)], 'pages/backups', ['backupsPage' => true]);
    }

    /** Administration des utilisateurs (users.js) : droit « users.manage » vérifié par la navigation et par l'API. */
    public function users(Request $req): Response
    {
        return $this->page($req, 'users', 'Utilisateurs', fn (AuthUser $u) => ['me' => $u->id], 'pages/users', ['usersAdmin' => true]);
    }

    /** Import / export (transfer.js) : l'import n'est proposé qu'avec le droit « data.import ». */
    public function transfer(Request $req): Response
    {
        return $this->page($req, 'transfer', 'Import / export', fn (AuthUser $u) => ['canImport' => $u->can('data.import'), 'canContacts' => $u->can('contacts.read'),
            'canImportContacts' => $u->can('data.import') && $u->can('contacts.write'), 'canCreate' => $u->can('appointments.write'), 'statuses' => self::STATUS_LABELS], 'pages/transfer', ['transfer' => true]);
    }

    public function module(Request $req, string $key, string $title, int $phase, ?string $perm = null, ?string $navKey = null): Response
    {
        $q = $req->query['q'] ?? null;
        return $this->page($req, $navKey ?? $key, $title, fn () => ['phase' => $phase, 'moduleTitle' => $title,
            'query' => is_string($q) ? mb_substr($q, 0, 200) : ''], 'pages/module', [], $perm);
    }

    public function settings(Request $req): Response
    {
        return $this->page($req, 'settings', 'Paramètres', fn (AuthUser $u) => [], 'pages/settings');
    }

    public function notFound(Request $req): Response
    {
        $ctx = $this->session($req);
        $html = $ctx
            ? View::render('pages/error', ['code' => 404, 'message' => 'Cette page n\'existe pas.'], null)
            : null;
        if ($ctx) {
            return Response::html($this->shell($ctx, '', 'Page introuvable', $html, []), 404);
        }
        return Response::html(View::render('pages/error', ['code' => 404, 'message' => 'Cette page n\'existe pas.', 'title' => 'Page introuvable'], 'bare'), 404);
    }

    /**
     * @param callable(AuthUser):array<string,mixed> $data
     * @param array<string,mixed> $layoutData
     */
    private function page(Request $req, string $navKey, string $title, callable $data, string $view, array $layoutData = [], ?string $perm = null): Response
    {
        $ctx = $this->session($req);
        if ($ctx === null) {
            $next = $req->path . ($req->query ? '?' . http_build_query($req->query) : '');
            return Response::redirect('/login?next=' . rawurlencode(self::safeNext($next)));
        }
        $u = $ctx['user'];
        if ($u->mustChangePassword && $navKey !== 'settings') {
            return Response::redirect('/settings?must_change=1');
        }
        $perm ??= Navigation::items()[$navKey]['perm'] ?? null;
        if ($perm !== null && !$u->can($perm)) {
            return Response::html($this->shell($ctx, $navKey, 'Accès refusé',
                View::render('pages/error', ['code' => 403, 'message' => 'Vous n\'avez pas la permission d\'accéder à cette page.'], null), $layoutData), 403);
        }
        $d = $data($u);
        return Response::html($this->shell($ctx, $navKey, $title, View::render($view, $d + ['user' => $u, 'title' => $title], null), $layoutData));
    }

    /**
     * @param array{user:AuthUser,csrf:string,session_id:string} $ctx
     * @param array<string,mixed> $extra
     */
    private function shell(array $ctx, string $navKey, string $title, string $content, array $extra): string
    {
        $u = $ctx['user'];
        return View::render('layout', $extra + [
            'user' => $u, 'csrf' => $ctx['csrf'], 'nav' => Navigation::visibleTo($u), 'current' => $navKey, 'title' => $title,
            'content' => $content, 'unread' => $this->stats->unreadNotifications($u->id), 'appName' => Config::get('app.name'),
            'version' => Config::get('app.version'), 'calendarNav' => false, 'manage' => false, 'dashboard' => false, 'transfer' => false, 'usersAdmin' => false, 'historyPage' => false, 'backupsPage' => false, 'view' => 'week', 'date' => '', 'views' => self::VIEWS,
            'canCreate' => $u->can('appointments.write'), 'tz' => $u->timezone,
            'syncCursor' => $this->sync?->maxId() ?? 0, 'syncAccess' => $this->perms->accessToken($u),
            'defaultReminder' => (string) ($this->settings?->notifications($u->id)['default_reminder'] ?? ''), 'syncInterval' => (int) Config::get('app.sync.interval', 30),
        ], null);
    }
}
