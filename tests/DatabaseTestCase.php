<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use PDO;

/** Base des tests d'intégration : base TEST_DB_* recréée et migrée avant chaque test (nom en _test obligatoire). */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected bool $migrate = true;

    public function setUp(): void
    {
        $name = Env::get('TEST_DB_NAME');
        if ($name === '' || Env::get('TEST_DB_USER') === '') {
            $this->skip('TEST_DB_* non défini');
        }
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException('Refus : la base de test doit se terminer par _test.');
        }
        $this->pdo = Database::connection([
            'driver' => 'mysql', 'host' => Env::get('TEST_DB_HOST', '127.0.0.1'), 'port' => Env::int('TEST_DB_PORT', 3306),
            'name' => $name, 'user' => Env::get('TEST_DB_USER'), 'password' => Env::get('TEST_DB_PASSWORD'),
            'ssl_mode' => 'disabled', 'ssl_ca' => '',
        ]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $this->pdo->exec("DROP TABLE `$t`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        if ($this->migrate) {
            (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        }
    }
}
