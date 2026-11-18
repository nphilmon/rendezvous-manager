<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

/** Contacts, catégories, calendriers et partage via la vraie table de routes (cookie + CSRF). */
final class ManagementApiTest extends DatabaseTestCase
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
        foreach ([['alice', 'user'], ['bob', 'user'], ['mgr', 'manager'], ['ro', 'readonly'], ['adm', 'admin']] as [$n, $role]) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'Test', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function call(string $as, string $method, string $path, array $body = [], array $query = [], bool $csrf = true): array
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($csrf && $method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        $res = Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
        return ['status' => $res->status, 'json' => json_decode($res->body, true) ?? []];
    }

    private function names(array $res): array
    {
        return array_column($res['json']['data'], 'name');
    }

    // ---- contacts ------------------------------------------------------------------------------------------------

    public function testContactCrudAndValidation(): void
    {
        $r = $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => 'Dupont', 'first_name' => 'Marie', 'company' => 'ACME', 'phone' => '+33 1 23 45 67 89', 'email' => 'marie@example.org', 'address' => '1 rue X', 'notes' => 'VIP']);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $c = $r['json']['data'];
        $this->assertSame('Marie Dupont', $c['name']);
        $u = $this->call('alice', 'PUT', '/api/v1/contacts/' . $c['id'], ['company' => 'Globex']);
        $this->assertSame('Globex', $u['json']['data']['company']);
        $this->assertSame('Dupont', $u['json']['data']['last_name'], 'champ omis conservé');
        $this->assertSame('VIP', $this->call('alice', 'GET', '/api/v1/contacts/' . $c['id'])['json']['data']['notes']);
        foreach ([['last_name' => ''], ['last_name' => str_repeat('x', 81)], ['last_name' => 'A', 'email' => 'pas-un-email'], ['last_name' => 'A', 'phone' => 'abc'],
            ['last_name' => 'A', 'company' => str_repeat('c', 121)], ['last_name' => ['x']], []] as $bad) {
            $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/contacts', $bad)['status'], json_encode($bad));
        }
        $this->assertSame(200, $this->call('alice', 'DELETE', '/api/v1/contacts/' . $c['id'])['status']);
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/contacts/' . $c['id'])['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM contacts WHERE deleted_at IS NOT NULL')->fetchColumn(), 'suppression logique');
        $this->assertSame(['created', 'updated', 'deleted'], $this->pdo->query("SELECT change_type FROM sync_changes WHERE entity_type = 'contact' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testContactOwnershipAndRoles(): void
    {
        $a = $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => 'Privé d\'Alice'])['json']['data'];
        $this->assertSame([], $this->call('bob', 'GET', '/api/v1/contacts')['json']['data'], 'Bob ne voit pas les contacts d\'Alice');
        $this->assertSame(404, $this->call('bob', 'GET', '/api/v1/contacts/' . $a['id'])['status']);
        $this->assertSame(404, $this->call('bob', 'PUT', '/api/v1/contacts/' . $a['id'], ['last_name' => 'Piraté'])['status']);
        $this->assertSame(404, $this->call('bob', 'DELETE', '/api/v1/contacts/' . $a['id'])['status']);
        $this->assertSame(1, count($this->call('mgr', 'GET', '/api/v1/contacts')['json']['data']), 'manage_all voit tous les contacts');
        $this->assertSame(200, $this->call('mgr', 'PUT', '/api/v1/contacts/' . $a['id'], ['company' => 'Géré'])['status']);
        $this->assertSame(403, $this->call('ro', 'POST', '/api/v1/contacts', ['last_name' => 'X'])['status'], 'lecture seule : pas d\'écriture');
        $this->assertSame(200, $this->call('ro', 'GET', '/api/v1/contacts')['status']);
        $this->assertSame(403, $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => 'X'], [], false)['status'], 'CSRF requis');
    }

    public function testContactSearchSortAndPagination(): void
    {
        foreach ([['Zola', 'Émile', 'Rougon', 'emile'], ['Abel', 'Jean', 'Macquart', 'jean'], ['Martin', 'Léa', 'Rougon', 'lea'], ['100%', 'Test', null, 'test']] as [$l, $f, $co, $mail]) {
            $r = $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => $l, 'first_name' => $f, 'company' => $co, 'email' => $mail . '@example.org']);
            $this->assertSame(201, $r['status'], json_encode($r['json']));
        }
        $list = fn (array $q) => array_column($this->call('alice', 'GET', '/api/v1/contacts', [], $q)['json']['data'], 'last_name');
        $this->assertSame(['100%', 'Abel', 'Martin', 'Zola'], $list([]));
        $this->assertSame(['Zola', 'Martin', 'Abel', '100%'], $list(['dir' => 'desc']));
        $this->assertSame(['Martin', 'Zola'], $list(['q' => 'rougon']));
        $this->assertSame(['Zola'], $list(['q' => 'Émile Zola']), 'recherche sur le nom complet');
        $this->assertSame(['Abel'], $list(['q' => 'jean@']));
        $this->assertSame(['100%'], $list(['q' => '100%']));
        $this->assertSame([], $list(['q' => '_']), 'jokers LIKE échappés');
        $p = $this->call('alice', 'GET', '/api/v1/contacts', [], ['per_page' => '2', 'page' => '2']);
        $this->assertSame(['Martin', 'Zola'], array_column($p['json']['data'], 'last_name'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 4, 'pages' => 2], $p['json']['meta']);
        foreach ([['sort' => 'password'], ['page' => '0'], ['per_page' => '9999'], ['dir' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/contacts', [], $bad)['status'], json_encode($bad));
        }
    }

    public function testContactLinkedAppointmentsRespectCalendarPermissions(): void
    {
        $c = $this->call('alice', 'POST', '/api/v1/contacts', ['last_name' => 'Client'])['json']['data'];
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (2,'B')");
        $ins = $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, contact_id, created_by, title, start_utc, end_utc) VALUES (UUID(), ?, ?, 1, ?, ?, ?)');
        $ins->execute([1, $c['id'], 'Visible', '2026-11-18 09:00:00', '2026-11-18 10:00:00']);
        $ins->execute([2, $c['id'], 'Dans un calendrier fermé', '2026-11-19 09:00:00', '2026-11-19 10:00:00']);
        $r = $this->call('alice', 'GET', '/api/v1/contacts/' . $c['id'] . '/appointments');
        $this->assertSame(['Visible'], array_column($r['json']['data'], 'title'));
        $this->assertSame(1, $r['json']['meta']['total']);
        $this->call('alice', 'DELETE', '/api/v1/contacts/' . $c['id']);
        $g = $this->call('alice', 'GET', '/api/v1/appointments', [], ['start' => '2026-11-18', 'end' => '2026-11-19'])['json']['data'];
        $this->assertSame('Client', $g[0]['contact'], 'le rendez-vous garde le nom du contact supprimé');
    }

    // ---- catégories ----------------------------------------------------------------------------------------------

    public function testCategoryManagementAndRoles(): void
    {
        $this->assertSame(403, $this->call('alice', 'POST', '/api/v1/categories', ['name' => 'Client'])['status'], 'utilisateur : lecture seule');
        $r = $this->call('mgr', 'POST', '/api/v1/categories', ['name' => 'Client', 'color' => '#9333EA']);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $id = $r['json']['data']['id'];
        $this->assertSame('#9333ea', $r['json']['data']['color']);
        $this->assertSame(1, count($this->call('alice', 'GET', '/api/v1/categories')['json']['data']), 'tous peuvent lire');
        $this->assertTrue($this->call('adm', 'GET', '/api/v1/categories')['json']['meta']['can_manage']);
        $this->assertTrue(!$this->call('alice', 'GET', '/api/v1/categories')['json']['meta']['can_manage']);
        $this->assertSame(422, $this->call('adm', 'POST', '/api/v1/categories', ['name' => 'client'])['status'], 'nom unique sans tenir compte de la casse');
        foreach ([['name' => ''], ['name' => str_repeat('x', 81)], ['name' => 'Ok', 'color' => 'rouge']] as $bad) {
            $this->assertSame(422, $this->call('adm', 'POST', '/api/v1/categories', $bad)['status'], json_encode($bad));
        }
        $u = $this->call('adm', 'PUT', '/api/v1/categories/' . $id, ['name' => 'Clients']);
        $this->assertSame('#9333ea', $u['json']['data']['color'], 'couleur conservée');
        $this->assertSame(200, $this->call('adm', 'PUT', '/api/v1/categories/' . $id, ['name' => 'Clients'])['status'], 'renommer avec son propre nom est permis');
        $this->call('adm', 'POST', '/api/v1/categories', ['name' => 'Interne']);
        $this->assertSame(422, $this->call('adm', 'PUT', '/api/v1/categories/' . $id, ['name' => 'INTERNE'])['status']);
    }

    public function testCategoryDeleteDetachesAndCountsAreScoped(): void
    {
        $cat = $this->call('adm', 'POST', '/api/v1/categories', ['name' => 'Client'])['json']['data'];
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (2,'B')");
        $ins = $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, category_id, created_by, title, start_utc, end_utc) VALUES (UUID(), ?, ?, 1, ?, ?, ?)');
        $ins->execute([1, $cat['id'], 'r1', '2026-11-18 09:00:00', '2026-11-18 10:00:00']);
        $ins->execute([2, $cat['id'], 'r2', '2026-11-19 09:00:00', '2026-11-19 10:00:00']);
        $this->assertSame(1, $this->call('alice', 'GET', '/api/v1/categories')['json']['data'][0]['appointment_count'], 'décompte limité aux calendriers lisibles');
        $d = $this->call('adm', 'DELETE', '/api/v1/categories/' . $cat['id']);
        $this->assertSame(2, $d['json']['detached'], 'les rendez-vous sont détachés');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments WHERE category_id IS NOT NULL')->fetchColumn());
        $this->assertSame([], $this->call('alice', 'GET', '/api/v1/categories')['json']['data']);
        $this->assertSame(404, $this->call('adm', 'DELETE', '/api/v1/categories/' . $cat['id'])['status']);
        $this->assertSame(201, $this->call('adm', 'POST', '/api/v1/categories', ['name' => 'Client'])['status'], 'le nom d\'une catégorie supprimée est réutilisable');
    }

    // ---- calendriers -----------------------------------------------------------------------------------------------

    public function testCalendarCreateUpdateDelete(): void
    {
        $r = $this->call('alice', 'POST', '/api/v1/calendars', ['name' => 'Équipe', 'color' => '#16A34A', 'kind' => 'team', 'visibility' => 'shared', 'description' => 'Notre équipe']);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $c = $r['json']['data'];
        $this->assertTrue($c['mine'] && $c['can']['manage'] && $c['can']['delete'] && $c['can']['share']);
        $this->assertSame('Europe/Paris', $c['timezone'], 'fuseau de l\'utilisateur par défaut');
        foreach ([['name' => ''], ['name' => 'X', 'kind' => 'autre'], ['name' => 'X', 'visibility' => 'public'], ['name' => 'X', 'timezone' => 'Mars/Phobos'], ['name' => 'X', 'color' => 'bleu']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/calendars', $bad)['status'], json_encode($bad));
        }
        $this->assertSame(403, $this->call('ro', 'POST', '/api/v1/calendars', ['name' => 'X'])['status'], 'lecture seule');
        $u = $this->call('alice', 'PUT', '/api/v1/calendars/' . $c['id'], ['name' => 'Équipe projet', 'version' => 1]);
        $this->assertSame(200, $u['status']);
        $this->assertSame(2, $u['json']['data']['version']);
        $this->assertSame('team', $u['json']['data']['kind'], 'champs omis conservés');
        $this->assertSame(409, $this->call('alice', 'PUT', '/api/v1/calendars/' . $c['id'], ['name' => 'Périmé', 'version' => 1])['status'], 'verrou optimiste');
        $this->assertSame(404, $this->call('bob', 'PUT', '/api/v1/calendars/' . $c['id'], ['name' => 'Piraté'])['status'], 'calendrier invisible : 404');
        $this->assertSame(200, $this->call('mgr', 'PUT', '/api/v1/calendars/' . $c['id'], ['color' => '#000000'])['status'], 'manage_all');
        $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc) VALUES (UUID(), {$c['id']}, 1, 'dedans', '2026-11-18 09:00:00', '2026-11-18 10:00:00')");
        $this->assertSame(200, $this->call('alice', 'DELETE', '/api/v1/calendars/' . $c['id'])['status']);
        $this->assertSame([], $this->call('alice', 'GET', '/api/v1/calendars')['json']['data']);
        $this->assertSame([], $this->call('alice', 'GET', '/api/v1/appointments', [], ['start' => '2026-11-18', 'end' => '2026-11-19'])['json']['data'], 'rendez-vous du calendrier supprimé invisibles');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn(), 'conservés en base');
    }

    public function testSharingFlow(): void
    {
        $id = $this->call('alice', 'POST', '/api/v1/calendars', ['name' => 'Partagé'])['json']['data']['id'];
        $this->assertSame(0, count($this->call('bob', 'GET', '/api/v1/calendars')['json']['data']));
        $s = $this->call('alice', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => [['user_id' => 2, 'can_create' => true]]]);
        $this->assertSame(200, $s['status'], json_encode($s['json']));
        $this->assertTrue($s['json']['data'][0]['can_read'] && $s['json']['data'][0]['can_create'] && !$s['json']['data'][0]['can_update'], 'lire est implicite');
        $b = $this->call('bob', 'GET', '/api/v1/calendars')['json']['data'];
        $this->assertSame(1, count($b));
        $this->assertTrue($b[0]['can']['create'] && !$b[0]['can']['update'] && !$b[0]['can']['manage'] && !$b[0]['mine']);
        $this->assertSame(403, $this->call('bob', 'GET', '/api/v1/calendars/' . $id . '/permissions')['status'], 'sans droit de partage');
        $this->assertSame(403, $this->call('bob', 'PUT', '/api/v1/calendars/' . $id, ['name' => 'Piraté'])['status'], 'le partage ne donne pas la propriété');
        // délégation du partage
        $this->call('alice', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => [['user_id' => 2, 'can_share' => true]]]);
        $d = $this->call('bob', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => [['user_id' => 2, 'can_share' => true], ['user_id' => 3, 'can_read' => true]]]);
        $this->assertSame(200, $d['status'], json_encode($d['json']));
        $this->assertSame(1, count($this->call('mgr', 'GET', '/api/v1/calendars')['json']['data']));
        // retrait : aucun droit = partage supprimé
        $this->call('alice', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => [['user_id' => 2, 'can_read' => false]]]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_permissions')->fetchColumn());
        $this->assertSame(0, count($this->call('bob', 'GET', '/api/v1/calendars')['json']['data']));
    }

    public function testSharingValidation(): void
    {
        $id = $this->call('alice', 'POST', '/api/v1/calendars', ['name' => 'C'])['json']['data']['id'];
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 4');
        foreach ([['permissions' => 'x'], ['permissions' => [['user_id' => 1, 'can_read' => true]]], ['permissions' => [['user_id' => 999, 'can_read' => true]]],
            ['permissions' => [['user_id' => 4, 'can_read' => true]]], ['permissions' => [['can_read' => true]]], []] as $bad) {
            $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/calendars/' . $id . '/permissions', $bad)['status'], json_encode($bad));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_permissions')->fetchColumn());
    }

    public function testReadonlyRoleCapsSharedWriteRights(): void
    {
        $id = $this->call('alice', 'POST', '/api/v1/calendars', ['name' => 'C'])['json']['data']['id'];
        $this->call('alice', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => [['user_id' => 4, 'can_read' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true, 'can_share' => true]]]);
        $c = $this->call('ro', 'GET', '/api/v1/calendars')['json']['data'][0];
        $this->assertTrue(!$c['can']['create'] && !$c['can']['update'] && !$c['can']['delete'] && !$c['can']['share'], 'le rôle « lecture seule » plafonne les droits accordés');
        $this->assertSame(403, $this->call('ro', 'PUT', '/api/v1/calendars/' . $id . '/permissions', ['permissions' => []])['status']);
    }

    public function testUserLookupIsMinimalAndScoped(): void
    {
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 2');
        $r = $this->call('alice', 'GET', '/api/v1/users/lookup', [], ['q' => 'test']);
        $this->assertSame(200, $r['status']);
        $users = array_column($r['json']['data'], 'username');
        $this->assertTrue(!in_array('alice', $users, true) && !in_array('bob', $users, true), 'ni soi-même ni les comptes désactivés');
        $this->assertTrue(in_array('mgr', $users, true));
        $this->assertTrue(!str_contains(json_encode($r['json']), 'example.org'), 'aucune adresse e-mail exposée');
        $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/users/lookup', [], ['q' => 'a'])['status']);
        $this->assertSame(403, $this->call('ro', 'GET', '/api/v1/users/lookup', [], ['q' => 'test'])['status']);
        $this->assertSame([], $this->call('alice', 'GET', '/api/v1/users/lookup', [], ['q' => "' OR 1=1 --"])['json']['data']);
    }
}
