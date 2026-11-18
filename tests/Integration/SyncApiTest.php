<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\SyncRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

/** Synchronisation incrémentale : curseur, visibilité par calendrier, suppressions, accès, purge. */
final class SyncApiTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        foreach ([['alice', 'user'], ['bob', 'user'], ['carol', 'user'], ['mgr', 'manager']] as [$n, $role]) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'Alice'), (1,'Partagé')");
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read) VALUES (2, 2, 1)');
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function call(string $as, string $method, string $path, array $body = [], array $query = []): array
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        $res = Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
        return ['status' => $res->status, 'json' => json_decode($res->body, true) ?? []];
    }

    private function sync(string $as, ?int $cursor): array
    {
        $r = $this->call($as, 'GET', '/api/v1/sync/changes', [], $cursor === null ? [] : ['cursor' => (string) $cursor]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        return $r['json'];
    }

    private int $seq = 0;

    private function create(string $as, int $cal, string $title = 'Secret'): int
    {
        $d = gmdate('Y-m-d', strtotime('2026-11-01') + 86400 * ++$this->seq);
        $r = $this->call($as, 'POST', '/api/v1/appointments', ['calendar_id' => $cal, 'title' => $title, 'start' => $d . 'T09:00:00Z', 'end' => $d . 'T10:00:00Z']);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        return $r['json']['data']['id'];
    }

    public function testAuthRequiredAndInitialCursor(): void
    {
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/sync/changes'), $this->router)->status);
        $j = $this->sync('alice', null);
        $this->assertSame(0, $j['cursor']);
        $this->assertSame([], $j['changes']);
        $this->assertSame(30, $j['poll_interval']);
        $id = $this->create('alice', 1);
        $this->assertSame(1, $this->sync('alice', null)['cursor'], 'sans curseur : point de départ courant, sans modification');
        $this->assertSame([], $this->sync('alice', null)['changes']);
        $this->assertTrue($id > 0);
    }

    public function testIncrementalChangesAndCursorAdvance(): void
    {
        $c0 = $this->sync('alice', null)['cursor'];
        $id = $this->create('alice', 1);
        $a = $this->sync('alice', $c0);
        $this->assertSame(1, count($a['changes']));
        $this->assertSame(['entity' => 'appointment', 'entity_id' => $id, 'change' => 'created', 'calendar_id' => 1, 'by_me' => true], array_intersect_key($a['changes'][0], array_flip(['entity', 'entity_id', 'change', 'calendar_id', 'by_me'])));
        $this->assertSame(0, count($this->sync('alice', $a['cursor'])['changes']), 'rien de nouveau');
        $this->call('alice', 'PUT', '/api/v1/appointments/' . $id, ['version' => 1, 'title' => 'Renommé']);
        $this->call('alice', 'DELETE', '/api/v1/appointments/' . $id, ['version' => 2]);
        $this->call('alice', 'POST', '/api/v1/appointments/' . $id . '/restore');
        $this->assertSame(['updated', 'deleted', 'restored'], array_column($this->sync('alice', $a['cursor'])['changes'], 'change'), 'modifications, suppressions et restaurations');
    }

    public function testOthersSeeSharedCalendarChangesButNeverOthers(): void
    {
        $c = ['bob' => $this->sync('bob', null)['cursor'], 'carol' => $this->sync('carol', null)['cursor']];
        $shared = $this->create('alice', 2, 'Titre confidentiel');
        $private = $this->create('alice', 1, 'Autre confidentiel');
        $b = $this->sync('bob', $c['bob']);
        $this->assertSame([$shared], array_column($b['changes'], 'entity_id'), 'Bob ne voit que le calendrier partagé');
        $this->assertTrue($b['changes'][0]['by_me'] === false);
        $this->assertSame([], $this->sync('carol', $c['carol'])['changes'], 'Carol n\'a accès à rien');
        $this->assertTrue(!in_array($private, array_column($b['changes'], 'entity_id'), true), 'aucun identifiant non autorisé');
        $this->assertTrue(!str_contains(json_encode($b), 'confidentiel'), 'aucun contenu');
        $this->assertTrue(count($this->sync('mgr', 0)['changes']) >= 2, 'manage_all voit tout');
    }

    public function testAccessTokenChangesWhenSharingChanges(): void
    {
        $a0 = $this->sync('carol', null)['access'];
        $this->call('alice', 'PUT', '/api/v1/calendars/2/permissions', ['permissions' => [['user_id' => 2, 'can_read' => true], ['user_id' => 3, 'can_read' => true]]]);
        $a1 = $this->sync('carol', null)['access'];
        $this->assertTrue($a0 !== $a1, 'accès accordé : jeton différent');
        $c = $this->sync('carol', null)['cursor'];
        $id = $this->create('alice', 2);
        $this->assertSame([$id], array_column($this->sync('carol', $c)['changes'], 'entity_id'));
        $this->call('alice', 'PUT', '/api/v1/calendars/2/permissions', ['permissions' => [['user_id' => 2, 'can_read' => true]]]);
        $this->assertSame($a0, $this->sync('carol', null)['access'], 'accès retiré : retour au jeton initial');
        $c2 = $this->sync('carol', null)['cursor'];
        $this->create('alice', 2);
        $this->assertSame([], $this->sync('carol', $c2)['changes'], 'plus de modifications après le retrait');
    }

    public function testCalendarCategoryAndContactChanges(): void
    {
        $bob = $this->sync('bob', null)['cursor'];
        $alice = $this->sync('alice', null)['cursor'];
        $this->call('mgr', 'POST', '/api/v1/categories', ['name' => 'Client']);
        $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => 'Privé']);
        $this->call('alice', 'PUT', '/api/v1/calendars/2', ['name' => 'Renommé']);
        $b = $this->sync('bob', $bob)['changes'];
        $this->assertSame([['category', 'created'], ['calendar', 'updated']], array_map(fn ($c) => [$c['entity'], $c['change']], $b), 'catégorie globale et calendrier partagé, mais pas le contact d\'Alice');
        $a = $this->sync('alice', $alice)['changes'];
        $this->assertSame(['category', 'contact', 'calendar'], array_column($a, 'entity'), 'Alice voit ses propres changements (autres onglets)');
    }

    public function testPaginationAndInvalidAndResetCursors(): void
    {
        $c = $this->sync('alice', null)['cursor'];
        $ins = $this->pdo->prepare("INSERT INTO sync_changes (calendar_id, entity_type, entity_id, change_type, changed_by, created_at) VALUES (1, 'appointment', ?, 'updated', 1, ?)");
        for ($i = 1; $i <= 620; $i++) {
            $ins->execute([$i, gmdate('Y-m-d H:i:s')]);
        }
        $p1 = $this->sync('alice', $c);
        $this->assertSame(500, count($p1['changes']));
        $this->assertTrue($p1['has_more']);
        $p2 = $this->sync('alice', $p1['cursor']);
        $this->assertSame(120, count($p2['changes']));
        $this->assertTrue(!$p2['has_more']);
        $this->assertSame(620 + $c, $p2['cursor']);
        foreach (['abc', '-1', '1.5', '99999999999999999999'] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/sync/changes', [], ['cursor' => $bad])['status'], $bad);
        }
        $r = $this->sync('alice', 999999);
        $this->assertTrue($r['reset'] && $r['cursor'] === $p2['cursor'], 'curseur inconnu : rechargement complet demandé');
    }

    public function testPruneRaisesFloorAndForcesResetForOldCursors(): void
    {
        $this->create('alice', 1);
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $this->pdo->exec("UPDATE sync_changes SET created_at = '$old'");
        $c = $this->sync('alice', null)['cursor'];
        $this->create('alice', 1);
        $repo = new SyncRepository($this->pdo);
        $this->assertSame(1, $repo->prune(30), 'seules les lignes de plus de 30 jours partent');
        $this->assertSame(1, $repo->floor());
        $this->assertTrue($this->sync('alice', 0)['reset'], 'curseur antérieur à la purge : reset');
        $this->assertTrue(!$this->sync('alice', $c)['reset'], 'curseur récent : incrémental');
        $this->assertSame(1, count($this->sync('alice', $c)['changes']));
        $this->assertSame(0, $repo->prune(30), 'idempotent');
    }

    public function testPageEmbedsCurrentCursor(): void
    {
        $this->create('alice', 1);
        $pages = new \App\Controllers\PageController($this->auth, new \App\Services\PermissionService($this->pdo), new \App\Repositories\StatsRepository($this->pdo), new SyncRepository($this->pdo));
        $r = $this->auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't');
        $html = $pages->dashboard(new Request('GET', '/', [], '', ['cookie' => 'rvm_session=' . $r['token']]))->body;
        $this->assertTrue(str_contains($html, '<meta name="sync-cursor" content="1">') && str_contains($html, 'name="sync-interval" content="30"') && preg_match('/name="sync-access" content="[0-9a-f]{12}"/', $html) === 1);
    }

    public function testMaintenancePurgesOldRowsOnly(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $this->create('alice', 1);
        $this->pdo->exec("UPDATE sync_changes SET created_at = '$old'");
        $this->create('alice', 1);
        $this->pdo->exec("INSERT INTO login_attempts (identifier, ip_address, succeeded, attempted_at) VALUES ('x', '1.1.1.1', 0, '$old'), ('y', '1.1.1.1', 0, '" . gmdate('Y-m-d H:i:s') . "')");
        $this->pdo->exec("INSERT INTO user_sessions (id, user_id, csrf_token, created_at, last_seen_at, expires_at) VALUES (REPEAT('a', 64), 1, '', '$old', '$old', '$old')");
        $live = (int) $this->pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn();
        $r = (new \App\Services\Maintenance($this->pdo))->run();
        $this->assertSame(['sync_changes' => 1, 'user_sessions' => 1, 'login_attempts' => 1, 'password_resets' => 0], $r);
        $this->assertSame($live - 1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(), 'les sessions actives sont conservées');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sync_changes')->fetchColumn());
    }
}
