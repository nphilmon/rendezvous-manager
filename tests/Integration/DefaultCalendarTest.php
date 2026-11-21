<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\CalendarRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\UserAdminService;
use Tests\DatabaseTestCase;

final class DefaultCalendarTest extends DatabaseTestCase
{
    private function user(UserRepository $u, string $name, string $role, string $tz = 'Europe/Paris'): int
    {
        return $u->create(['role_code' => $role, 'username' => $name, 'email' => "$name@example.org", 'first_name' => $name, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9'), 'timezone' => $tz]);
    }

    public function testNewAccountsGetAPersonalCalendarExceptReadOnlyOnes(): void
    {
        $users = new UserRepository($this->pdo);
        $svc = new UserAdminService($this->pdo, $users, new AuditLogger($this->pdo));
        $admin = $users->findAuthUser($this->user($users, 'root', 'admin'));
        $base = ['email' => 'a@example.org', 'first_name' => 'A', 'last_name' => 'B', 'timezone' => 'America/Toronto'];
        $a = $svc->create($admin, $base + ['username' => 'alice', 'role' => 'user'], 'x')['user']['id'];
        $r = $svc->create($admin, ['email' => 'r@example.org', 'username' => 'reader', 'role' => 'readonly'] + $base, 'x')['user']['id'];
        $cals = $this->pdo->query('SELECT owner_id, name, kind, visibility, timezone FROM calendars WHERE owner_id IN (' . $a . ',' . $r . ')')->fetchAll();
        $this->assertSame([['owner_id' => $a, 'name' => 'Mon calendrier', 'kind' => 'personal', 'visibility' => 'private', 'timezone' => 'America/Toronto']], array_map(fn ($c) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $c), $cals));
    }

    public function testEnsureDefaultIsIdempotentAndRespectsExistingCalendars(): void
    {
        $users = new UserRepository($this->pdo);
        $id = $this->user($users, 'bob', 'user');
        $repo = new CalendarRepository($this->pdo);
        $this->assertTrue($repo->ensureDefault($id, 'Europe/Paris') !== null);
        $this->assertSame(null, $repo->ensureDefault($id, 'Europe/Paris'), 'jamais de doublon');
        $other = $this->user($users, 'carol', 'user');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($other, 'Le mien')");
        $this->assertSame(null, $repo->ensureDefault($other, 'Europe/Paris'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM calendars WHERE owner_id = $other")->fetchColumn());
        $this->pdo->exec("UPDATE calendars SET deleted_at = NOW() WHERE owner_id = $other");
        $this->assertTrue($repo->ensureDefault($other, 'Europe/Paris') !== null, 'un calendrier supprimé ne compte pas');
    }

    public function testMigrationGivesExistingWritersACalendarOnce(): void
    {
        // base migrée jusqu'à 0007, comptes créés, puis application de 0008
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $this->pdo->exec("DROP TABLE `$t`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $dir = sys_get_temp_dir() . '/rvm-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $f) {
            if (basename($f) < '0008') {
                copy($f, $dir . '/' . basename($f));
            }
        }
        (new Migrator($this->pdo, $dir))->migrate();
        $users = new UserRepository($this->pdo);
        $admin = $this->user($users, 'admin', 'admin');
        $reader = $this->user($users, 'ro', 'readonly');
        $has = $this->user($users, 'has', 'user');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($has, 'Existant')");
        copy(dirname(__DIR__, 2) . '/database/migrations/0008_default_calendars.sql', $dir . '/0008_default_calendars.sql');
        (new Migrator($this->pdo, $dir))->migrate();
        $names = fn (int $id) => $this->pdo->query("SELECT GROUP_CONCAT(name) FROM calendars WHERE owner_id = $id")->fetchColumn();
        $this->assertSame('Mon calendrier', $names($admin));
        $this->assertSame(null, $names($reader) ?: null, 'lecture seule : aucun');
        $this->assertSame('Existant', $names($has), 'compte déjà pourvu : inchangé');
        (new Migrator($this->pdo, $dir))->migrate();
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM calendars WHERE owner_id = $admin")->fetchColumn(), 'idempotent');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}
