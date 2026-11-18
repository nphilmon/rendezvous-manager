<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use PDO;
use Tests\TestCase;

/** Utilise exclusivement la base TEST_DB_* ; refuse tout nom ne finissant pas par _test. */
final class MigrationTest extends TestCase
{
    private PDO $pdo;

    public function setUp(): void
    {
        $name = Env::get('TEST_DB_NAME');
        if ($name === '' || Env::get('TEST_DB_USER') === '') {
            $this->skip('TEST_DB_* non défini');
        }
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException('Refus : la base de test doit se terminer par _test.');
        }
        $cfg = ['host' => Env::get('TEST_DB_HOST', '127.0.0.1'), 'port' => Env::int('TEST_DB_PORT', 3306),
            'name' => $name, 'user' => Env::get('TEST_DB_USER'), 'password' => Env::get('TEST_DB_PASSWORD'),
            'ssl_mode' => 'disabled', 'ssl_ca' => '', 'driver' => 'mysql'];
        $this->pdo = Database::connection($cfg);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $this->pdo->exec("DROP TABLE `$t`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
    }

    public function testMigrateCreatesSchemaAndIsIdempotent(): void
    {
        $m = $this->migrator();
        $this->assertTrue(count($m->migrate()) >= 4);
        $this->assertSame([], $m->migrate());
        $this->assertSame([], $m->pending());
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'roles', 'permissions', 'role_permissions', 'calendars', 'calendar_permissions', 'appointments',
            'appointment_recurrence', 'appointment_exceptions', 'contacts', 'categories', 'reminders',
            'notification_deliveries', 'push_subscriptions', 'settings', 'user_sessions', 'audit_logs',
            'sync_changes', 'schema_migrations'] as $t) {
            $this->assertTrue(in_array($t, $tables, true), "table manquante : $t");
        }
    }

    public function testRolesSeeded(): void
    {
        $this->migrator()->migrate();
        $codes = $this->pdo->query('SELECT code FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['admin', 'manager', 'user', 'readonly'], $codes);
        $n = fn (int $r) => (int) $this->pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id=$r")->fetchColumn();
        $this->assertTrue($n(1) > $n(2) && $n(2) > $n(3) && $n(3) > $n(4), 'hiérarchie des permissions');
    }

    public function testTablesAreInnoDbUtf8mb4(): void
    {
        $this->migrator()->migrate();
        $bad = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables t
            WHERE t.table_schema = DATABASE() AND (t.engine <> 'InnoDB' OR t.table_collation NOT LIKE 'utf8mb4%')")->fetchColumn();
        $this->assertSame(0, (int) $bad);
    }

    public function testConstraintsEnforced(): void
    {
        $this->migrator()->migrate();
        $this->pdo->exec("INSERT INTO users (role_id, username, email, first_name, last_name, password_hash)
                          VALUES (1,'a','a@x.org','A','A','h')");
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'c')");
        $ins = $this->pdo->prepare("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc)
                                    VALUES (UUID(),1,1,'t',?,?)");
        $ins->execute(['2026-01-01 10:00:00', '2026-01-01 11:00:00']);
        $failed = false;
        try {
            $ins->execute(['2026-01-01 12:00:00', '2026-01-01 11:00:00']); // fin avant début
        } catch (\PDOException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'CHECK end >= start non appliqué');
        $failed = false;
        try {
            $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (999,'x')"); // FK
        } catch (\PDOException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'clé étrangère non appliquée');
    }

    public function testModifiedMigrationIsRejected(): void
    {
        $this->migrator()->migrate();
        $this->pdo->exec("UPDATE schema_migrations SET checksum = REPEAT('0',64) WHERE version='0001'");
        try {
            $this->migrator()->migrate();
            $this->assertTrue(false, 'exception attendue');
        } catch (\RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), '0001'));
        }
    }
}
