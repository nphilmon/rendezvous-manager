<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

/** Type de lieu des rendez-vous (sur place, visioconférence, téléphone, autre) et validation du lien de visioconférence. */
final class LocationTypeTest extends DatabaseTestCase
{
    private Router $router;
    private string $cookie = '';
    private string $csrf = '';

    public function setUp(): void
    {
        parent::setUp();
        $users = new UserRepository($this->pdo);
        $auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        $users->create(['role_code' => 'user', 'username' => 'alice', 'email' => 'alice@example.org', 'first_name' => 'Alice', 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1, 'Alice')");
        $r = $auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't');
        $this->cookie = 'rvm_session=' . $r['token'];
        $this->csrf = $r['csrf'];
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        $h = ['cookie' => $this->cookie] + ($method !== 'GET' ? ['x-csrf-token' => $this->csrf] : []);
        return Application::handle(new Request($method, $path, [], $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    private function create(array $extra): Response
    {
        return $this->call('POST', '/api/v1/appointments', $extra + ['ignore_conflicts' => true, 'calendar_id' => 1, 'title' => 'RDV', 'timezone' => 'Europe/Paris', 'start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z']);
    }

    public function testDefaultsToOnsiteAndRoundTrips(): void
    {
        $r = $this->create(['location' => 'Salle 3', 'address' => '8 Boulevard du Port 80000 Amiens']);
        $this->assertSame(201, $r->status, $r->body);
        $a = json_decode($r->body, true)['data'];
        $this->assertSame('onsite', $a['location_type']);
        $r2 = $this->call('PUT', '/api/v1/appointments/' . $a['id'], ['location_type' => 'phone', 'location' => '06 12 34 56 78', 'address' => null, 'version' => $a['version']]);
        $this->assertSame(200, $r2->status, $r2->body);
        $g = json_decode($this->call('GET', '/api/v1/appointments/' . $a['id'])->body, true)['data'];
        $this->assertSame('phone', $g['location_type']);
        $this->assertSame('06 12 34 56 78', $g['location']);
    }

    public function testVideoNeedsAWebLink(): void
    {
        $ok = $this->create(['location_type' => 'video', 'location' => 'Zoom', 'address' => 'https://zoom.example/j/123?pwd=abc']);
        $this->assertSame(201, $ok->status, $ok->body);
        foreach (['javascript:alert(1)', 'ftp://x.example/a', 'pas un lien', 'https://user:pw@x.example/', 'https://x.example/a b'] as $bad) {
            $r = $this->create(['location_type' => 'video', 'address' => $bad]);
            $this->assertSame(422, $r->status, $bad);
            $this->assertTrue(isset(json_decode($r->body, true)['error']['details']['address']), $bad);
        }
        // sur place : l'adresse reste libre
        $this->assertSame(201, $this->create(['location_type' => 'onsite', 'address' => 'javascript:ceci est juste du texte'])->status);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $r = $this->create(['location_type' => 'teleportation']);
        $this->assertSame(422, $r->status);
        $this->assertTrue(isset(json_decode($r->body, true)['error']['details']['location_type']));
    }
}
