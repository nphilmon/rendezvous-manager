<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Config;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Backup\BackupService;
use App\Services\Maintenance;
use PDO;
use Tests\DatabaseTestCase;

final class BackupAuditTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    private string $tmp;
    /** @var list<string> fichiers déjà présents dans le dossier de sauvegarde par défaut avant le test */
    private array $before = [];
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $this->tmp = sys_get_temp_dir() . '/rvm-bk-' . bin2hex(random_bytes(4));
        $this->before = glob((string) Config::get('app.backup.dir') . '/*') ?: [];
        $users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        foreach (['admin' => 'admin', 'alice' => 'user', 'bob' => 'user'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("UPDATE users SET timezone='Europe/Paris'");
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (2,'A'), (3,'B')");
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmp);
        foreach (glob((string) Config::get('app.backup.dir') . '/*') ?: [] as $f) {
            if (!in_array($f, $this->before, true)) {
                unlink($f);
            }
        }
    }

    private function svc(): BackupService
    {
        return new BackupService($this->pdo, $this->tmp, 'test');
    }

    private function call(string $as, string $method, string $path, array $body = [], array $query = []): Response
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        return Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    private function j(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    /** @return array<string,mixed> */
    private function appt(string $as, array $over = []): array
    {
        $r = $this->call($as, 'POST', '/api/v1/appointments', $over + ['calendar_id' => 1, 'title' => 'Premier', 'timezone' => 'Europe/Paris', 'start' => '2026-11-18T09:00:00Z', 'end' => '2026-11-18T10:00:00Z']);
        $this->assertSame(201, $r->status, $r->body);
        return $this->j($r)['data'];
    }

    // ---- sauvegardes -------------------------------------------------------

    public function testBackupRoundTripPreservesTrickyValuesAndSkipsSessions(): void
    {
        $this->call('alice', 'GET', '/api/v1/auth/me'); // crée une session
        $notes = "ligne 1\nligne 2 avec 'apostrophes', \"guillemets\", antislash \\ et émoji 😀\r\nfin;";
        $this->pdo->prepare("INSERT INTO contacts (owner_id, last_name, first_name, notes, created_at, updated_at) VALUES (2, ?, NULL, ?, NOW(), NOW())")->execute(['O\'Brien', $notes]);
        $this->appt('alice', ['description' => "<script>alert(1)</script>\n--; DROP TABLE users; --"]);
        $before = $this->snapshot();
        $meta = $this->svc()->create(1);
        $path = $this->svc()->path($meta['name']);
        $this->assertSame(0600, fileperms($path) & 0777, 'fichier lisible par le seul propriétaire');
        $this->assertSame(hash_file('sha256', $path), $meta['sha256']);
        $this->assertSame(0, $meta['tables']['user_sessions'], 'les sessions ne sont pas sauvegardées');
        $this->assertTrue($meta['tables']['contacts'] === 1 && $meta['tables']['appointments'] === 1 && $meta['rows'] > 10);
        $sql = (string) gzdecode((string) file_get_contents($path));
        $this->assertTrue(str_starts_with($sql, '-- RendezVous Manager backup') && str_ends_with(rtrim($sql), '-- END rvm-backup-1'));
        $this->assertTrue(!str_contains($sql, "INSERT INTO `user_sessions`"), 'aucune donnée de session');
        foreach (explode("\n", rtrim($sql)) as $line) {
            $this->assertTrue($line === '' || str_starts_with($line, '--') || str_ends_with($line, ';'), 'une instruction par ligne : ' . substr($line, 0, 60));
        }
        $this->assertSame(['ok' => true, 'problems' => []], $this->svc()->verify($path));

        // on abîme la base puis on restaure
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec('DELETE FROM appointments');
        $this->pdo->exec('DELETE FROM contacts');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec("UPDATE users SET first_name='Casse'");
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec('DROP TABLE reminders');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->svc()->restore($path);
        $this->assertSame($before, $this->snapshot(), 'base identique après restauration');
        $this->assertSame($notes, $this->pdo->query('SELECT notes FROM contacts')->fetchColumn(), 'retours à la ligne, apostrophes, antislash et émoji intacts');
        $this->assertSame(1, (int) $this->pdo->query('SHOW TABLES LIKE "reminders"')->rowCount(), 'table supprimée recréée');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(), 'restauration : sessions vides');
        $this->assertSame(1, (int) $this->pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn(), 'contraintes réactivées');
    }

    /** @return array<string,string> somme de contrôle du contenu de chaque table sauvegardée */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (in_array($t, ['user_sessions', 'login_attempts'], true)) {
                continue;
            }
            $rows = $this->pdo->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll(PDO::FETCH_NUM);
            $out[$t] = md5(json_encode($rows));
        }
        return $out;
    }

    public function testVerifyDetectsTamperingTruncationAndMissingControlFile(): void
    {
        $meta = $this->svc()->create();
        $path = $this->svc()->path($meta['name']);
        $good = (string) file_get_contents($path);
        file_put_contents($path, substr($good, 0, (int) (strlen($good) / 2)));
        $r = $this->svc()->verify($path);
        $this->assertTrue(!$r['ok'] && count($r['problems']) >= 2, 'fichier tronqué : somme et archive en défaut ' . json_encode($r));
        $bad = $good;
        $bad[40] = $bad[40] === 'a' ? 'b' : 'a';
        file_put_contents($path, $bad);
        $this->assertTrue(!$this->svc()->verify($path)['ok'], 'octet modifié');
        file_put_contents($path, $good);
        $this->assertTrue($this->svc()->verify($path)['ok']);
        unlink($path . '.json');
        $r = $this->svc()->verify($path);
        $this->assertTrue(!$r['ok'] && str_contains($r['problems'][0], 'contrôle'), 'fichier de contrôle absent');
        // une archive valide mais sans marqueur de fin n'est pas restaurable
        $half = $this->tmp . '/rvm-20260101-000000.sql.gz';
        file_put_contents($half, gzencode("-- format: rvm-backup-1\nDROP TABLE IF EXISTS `users`;\n"));
        file_put_contents($half . '.json', json_encode(['sha256' => hash_file('sha256', $half)]));
        $this->assertTrue(!$this->svc()->verify($half)['ok']);
        $this->assertThrowsRuntime(fn () => $this->svc()->restore($half), 'restauration refusée');
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'rien n\'a été exécuté');
    }

    public function testNamesAreStrictlyValidatedAndRotationKeepsTheNewest(): void
    {
        $s = $this->svc();
        foreach (['../../etc/passwd', 'rvm-20260101-000000.sql.gz/../x', '/etc/passwd', 'rvm-2026.sql.gz', 'rvm-20260101-000000.sql.gz.json', "rvm-20260101-000000.sql.gz\0"] as $bad) {
            $this->assertThrowsHttp(fn () => $s->path($bad), 404, $bad);
        }
        $n = [];
        foreach (['2026-01-01 03:00:00', '2026-01-02 03:00:00', '2026-01-03 03:00:00', '2026-01-04 03:00:00'] as $d) {
            $n[] = $s->create(null, new \DateTimeImmutable($d, new \DateTimeZone('UTC')))['name'];
        }
        $this->assertSame(array_reverse($n), array_column($s->list(), 'name'), 'du plus récent au plus ancien');
        $this->assertSame([$n[1], $n[0]], $s->prune(2), 'les deux plus anciennes supprimées');
        $this->assertSame([$n[3], $n[2]], array_column($s->list(), 'name'));
        $this->assertTrue(!is_file($this->tmp . '/' . $n[0] . '.json'), 'fichier de contrôle supprimé avec la sauvegarde');
        $this->assertSame([$n[2]], $s->prune(0), 'au moins une sauvegarde est toujours conservée');
        $this->assertSame([$n[3]], array_column($s->list(), 'name'));
    }

    public function testConcurrentBackupIsRefused(): void
    {
        $other = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', Env::get('TEST_DB_HOST', '127.0.0.1'), Env::int('TEST_DB_PORT', 3306), Env::get('TEST_DB_NAME')), Env::get('TEST_DB_USER'), Env::get('TEST_DB_PASSWORD'));
        $other->query("SELECT GET_LOCK('rvm_backup', 0)")->fetchColumn();
        $this->assertThrowsHttp(fn () => $this->svc()->create(), 409, 'sauvegarde simultanée');
        $other->query("SELECT RELEASE_LOCK('rvm_backup')")->fetchColumn();
        $this->assertTrue($this->svc()->create()['size'] > 0, 'possible une fois le verrou libéré');
    }

    public function testBackupApiPermissionsLifecycleAndAudit(): void
    {
        foreach ([['GET', '/api/v1/backups'], ['POST', '/api/v1/backups'], ['GET', '/api/v1/backups/x/download'], ['DELETE', '/api/v1/backups/x']] as [$m, $p]) {
            $this->assertSame(403, $this->call('alice', $m, $p)->status, "$m $p");
        }
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/backups', [], '', [], '203.0.113.1'), $this->router)->status);
        $this->call('admin', 'GET', '/api/v1/backups');
        $noCsrf = Application::handle(new Request('POST', '/api/v1/backups', [], '', ['cookie' => $this->sessions['admin']['cookie']], '203.0.113.1'), $this->router);
        $this->assertSame(403, $noCsrf->status, 'CSRF exigé');
        $c = $this->call('admin', 'POST', '/api/v1/backups');
        $this->assertSame(201, $c->status, $c->body);
        $meta = $this->j($c)['data'];
        $this->assertTrue($this->j($c)['verified'] === true);
        $list = $this->j($this->call('admin', 'GET', '/api/v1/backups'))['data'];
        $this->assertSame($meta['name'], $list[0]['name']);
        $dl = $this->call('admin', 'GET', '/api/v1/backups/' . $meta['name'] . '/download');
        $this->assertTrue($dl->status === 200 && $dl->headers['Content-Type'] === 'application/gzip' && str_contains($dl->headers['Content-Disposition'], 'attachment'));
        $this->assertSame($meta['sha256'], hash('sha256', $dl->body), 'le téléchargement est identique au fichier contrôlé');
        $this->assertSame(true, $this->j($this->call('admin', 'POST', '/api/v1/backups/' . $meta['name'] . '/verify'))['data']['ok']);
        $this->assertSame(404, $this->call('admin', 'GET', '/api/v1/backups/..%2F..%2Fetc%2Fpasswd/download')->status);
        $this->assertSame(404, $this->call('admin', 'GET', '/api/v1/backups/rvm-20200101-000000.sql.gz/download')->status);
        $this->assertSame(200, $this->call('admin', 'DELETE', '/api/v1/backups/' . $meta['name'])->status);
        $this->assertSame(404, $this->call('admin', 'GET', '/api/v1/backups/' . $meta['name'] . '/download')->status);
        $actions = $this->pdo->query("SELECT action FROM audit_logs WHERE action LIKE 'backup.%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['backup.create', 'backup.download', 'backup.delete'], $actions);
    }

    // ---- journal d'audit et historique -----------------------------------------

    public function testAppointmentUpdateRecordsFieldChangesAndHistoryIsScoped(): void
    {
        $a = $this->appt('alice', ['description' => 'secret']);
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => $a['version'], 'title' => 'Renommé', 'description' => 'autre chose', 'status' => 'confirmed', 'start' => '2026-11-18T10:00:00Z', 'end' => '2026-11-18T11:00:00Z']);
        $this->assertSame(200, $r->status, $r->body);
        $h = $this->j($this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'] . '/history'))['data'];
        $this->assertSame(['appointment.update', 'appointment.create'], array_column($h, 'action'), 'plus récent d\'abord');
        $ch = $h[0]['details']['changes'];
        $this->assertSame(['Premier', 'Renommé'], $ch['title']);
        $this->assertSame(['scheduled', 'confirmed'], $ch['status']);
        $this->assertSame(['2026-11-18 09:00:00', '2026-11-18 10:00:00'], $ch['start_utc']);
        $this->assertSame('modifié', $ch['description'], 'le contenu long n\'est pas copié dans le journal');
        $this->assertTrue(!str_contains(json_encode($h), 'secret') && !str_contains(json_encode($h), 'autre chose'));
        $this->assertTrue(!array_key_exists('ip', $h[0]) && $h[0]['user']['name'] === 'Alice T' && $h[0]['label'] === 'Rendez-vous modifié');
        $this->assertSame(404, $this->call('bob', 'GET', '/api/v1/appointments/' . $a['id'] . '/history')->status, 'calendrier non lisible : 404');
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/appointments/999/history')->status);
        $this->call('alice', 'DELETE', '/api/v1/appointments/' . $a['id'], ['version' => $r->status === 200 ? $this->j($r)['data']['version'] : 1]);
        $this->assertSame('appointment.delete', $this->j($this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'] . '/history'))['data'][0]['action'], 'historique disponible pour un rendez-vous supprimé');
        $this->assertSame(200, $this->call('admin', 'GET', '/api/v1/appointments/' . $a['id'] . '/history')->status, 'administrateur : gère tous les calendriers');
    }

    public function testAuditApiFiltersPaginationAndPermission(): void
    {
        $this->assertSame(403, $this->call('alice', 'GET', '/api/v1/audit')->status);
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/audit', [], '', [], '203.0.113.1'), $this->router)->status);
        $a = $this->appt('alice');
        $this->call('alice', 'POST', '/api/v1/auth/password', ['current_password' => 'Correct-Horse-9', 'new_password' => 'Nouveau-Secret-42']);
        $get = fn (array $q) => $this->j($this->call('admin', 'GET', '/api/v1/audit', [], $q));
        $all = $get([]);
        $this->assertTrue($all['meta']['total'] >= 4 && isset($all['data'][0]['ip']) && in_array('appointment.create', $all['meta']['actions'], true));
        $this->assertSame(['appointment.create'], array_unique(array_column($get(['action' => 'appointment.create'])['data'], 'action')));
        $this->assertTrue(count(array_intersect(['auth.login', 'auth.password_changed'], array_column($get(['action' => 'auth.'])['data'], 'action'))) === 2, 'préfixe « auth. »');
        foreach ($get(['action' => 'auth.'])['data'] as $row) {
            $this->assertTrue(str_starts_with($row['action'], 'auth.'));
        }
        $this->assertSame(['appointment'], array_unique(array_column($get(['entity_type' => 'appointment', 'entity_id' => (string) $a['id']])['data'], 'entity_type')));
        foreach ($get(['user_id' => '2'])['data'] as $row) {
            $this->assertSame(2, $row['user']['id']);
        }
        $this->assertSame(0, $get(['q' => '%'])['meta']['total'], 'jokers échappés');
        $this->assertTrue($get(['q' => 'alice'])['meta']['total'] >= 2);
        $this->pdo->exec("UPDATE audit_logs SET created_at = '2026-01-01 23:30:00' WHERE action = 'appointment.create'");
        $on = fn (array $q) => $get($q + ['action' => 'appointment.create'])['meta']['total'];
        $this->assertSame([1, 0, 0, 1], [$on(['from' => '2026-01-02']), $on(['from' => '2026-01-03']), $on(['to' => '2026-01-01']), $on(['to' => '2026-01-02'])], '23:30 UTC le 1er = 00:30 le 2 à Paris : bornes dans le fuseau de l\'utilisateur');
        $p = $get(['per_page' => 2, 'page' => 2]);
        $this->assertTrue(count($p['data']) === 2 && $p['meta']['pages'] >= 2 && $p['data'][0]['id'] < $get(['per_page' => 2])['data'][1]['id'], 'pagination décroissante');
        foreach ([['action' => 'x y'], ['action' => "a'b"], ['user_id' => 'x'], ['entity_id' => '1;2'], ['from' => 'hier'], ['to' => '2026-13-45'], ['entity_type' => 'A B']] as $bad) {
            $this->assertSame(422, $this->call('admin', 'GET', '/api/v1/audit', [], $bad)->status, json_encode($bad));
        }
        $this->assertTrue(!str_contains($this->call('admin', 'GET', '/api/v1/audit', [], ['per_page' => 100])->body, 'Nouveau-Secret'), 'aucun mot de passe dans le journal');
    }

    public function testLabelsAndRetention(): void
    {
        $this->assertSame('Rendez-vous annulé (toute la série)', AuditRepository::label('appointment.cancel_series'));
        $this->assertSame('Rendez-vous modifié (une occurrence)', AuditRepository::label('appointment.update_occurrence'));
        $this->assertSame('Rendez-vous supprimé (cette occurrence et les suivantes)', AuditRepository::label('appointment.delete_following'));
        $this->assertSame('truc.inconnu', AuditRepository::label('truc.inconnu'));
        $log = new AuditLogger($this->pdo);
        $log->log('auth.login', 1);
        $this->pdo->exec("UPDATE audit_logs SET created_at = '2020-01-01 00:00:00'");
        $log->log('auth.logout', 1);
        $this->assertSame(1, (new Maintenance($this->pdo))->run(30, 30, 7, 365)['audit_logs']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
        $this->assertTrue(!isset((new Maintenance($this->pdo))->run()['audit_logs']), 'rétention désactivée par défaut');
    }

    private function assertThrowsHttp(callable $f, int $status, string $msg): void
    {
        try {
            $f();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status, $msg);
            return;
        }
        $this->assertTrue(false, "HTTP $status attendu : $msg");
    }

    private function assertThrowsRuntime(callable $f, string $msg): void
    {
        try {
            $f();
        } catch (\RuntimeException) {
            return;
        }
        $this->assertTrue(false, "exception attendue : $msg");
    }
}
