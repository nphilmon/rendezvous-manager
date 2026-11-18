<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use Installer\ArraySessionStore;
use Installer\DatabaseSetup;
use Installer\InstallationLock;
use Installer\InstallerController;
use Installer\PrerequisiteChecker;
use Tests\DatabaseTestCase;

/** Assistant d'installation, testé contre la base TEST_DB_* (vide) dans un répertoire temporaire. */
final class InstallerTest extends DatabaseTestCase
{
    protected bool $migrate = false;
    private string $root;
    private InstallationLock $lock;
    private InstallerController $ctl;
    private ArraySessionStore $sess;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/rvm_inst_' . bin2hex(random_bytes(4));
        foreach (['logs', 'exports', 'backups', 'sessions'] as $d) {
            mkdir($this->root . '/storage/' . $d, 0777, true);
        }
        $this->lock = new InstallationLock($this->root . '/storage/install.lock', $this->root . '/storage/install.token');
        $this->ctl = new InstallerController(
            $this->lock,
            new DatabaseSetup($this->root . '/.env', dirname(__DIR__, 2) . '/database/migrations'),
            new PrerequisiteChecker($this->root, true),
            $this->sess = new ArraySessionStore(),
            dirname(__DIR__, 2) . '/installer/views',
        );
    }

    /** @return array<string,string> */
    private function dbForm(array $over = []): array
    {
        return $over + ['type' => 'mariadb', 'host' => Env::get('TEST_DB_HOST', '127.0.0.1'), 'port' => Env::get('TEST_DB_PORT', '3306'),
            'name' => Env::get('TEST_DB_NAME'), 'user' => Env::get('TEST_DB_USER'), 'password' => Env::get('TEST_DB_PASSWORD'),
            'ssl_mode' => 'disabled', 'ssl_ca' => ''];
    }

    private function get(string $path): Response
    {
        return $this->ctl->handle(new Request('GET', $path, [], '', [], '203.0.113.9', [], true));
    }

    /** @param array<string,mixed> $form */
    private function post(string $path, array $form, bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $form['_csrf'] = $this->csrf();
        }
        return $this->ctl->handle(new Request('POST', $path, [], '', [], '203.0.113.9', $form, true));
    }

    private function csrf(): string
    {
        $this->get('/install'); // garantit l'initialisation de la session
        return (string) $this->sess->get('csrf', '');
    }

    private function authorize(): void
    {
        $token = $this->lock->issueToken();
        $this->get('/install'); // initialise la session
        $r = $this->post('/install/token', ['token' => $token]);
        $this->assertSame(302, $r->status);
    }

    public function testWizardIsGatedByOneTimeToken(): void
    {
        $this->assertSame(302, $this->get('/install/database')->status, 'étape protégée sans jeton');
        $token = $this->lock->issueToken();
        $this->assertSame(401, $this->post('/install/token', ['token' => 'faux'])->status);
        $this->assertSame(302, $this->post('/install/token', ['token' => $token])->status);
        $this->assertTrue(!is_file($this->root . '/storage/install.token'), 'jeton consommé');
        $this->assertTrue(str_contains($this->get('/install')->body, 'Bienvenue'));

        $other = new InstallationLock($this->root . '/storage/install.lock', $this->root . '/storage/install.token');
        $this->assertTrue(!$other->consume($token), 'réutilisation impossible');
    }

    public function testTokenFileStoresOnlyHash(): void
    {
        $t = $this->lock->issueToken();
        $raw = (string) file_get_contents($this->root . '/storage/install.token');
        $this->assertTrue(!str_contains($raw, $t));
        $this->assertSame(0600, fileperms($this->root . '/storage/install.token') & 0777);
    }

    public function testTokenInvalidatedAfterTooManyFailures(): void
    {
        $this->lock->issueToken();
        for ($i = 0; $i < 10; $i++) {
            $this->lock->consume('x' . $i);
        }
        $this->assertTrue(!$this->lock->hasValidToken());
    }

    public function testCsrfEnforced(): void
    {
        $this->authorize();
        $this->assertSame(403, $this->post('/install/database', $this->dbForm(), false)->status);
    }

    public function testPrerequisitesPage(): void
    {
        $this->authorize();
        $b = $this->get('/install/prerequisites')->body;
        foreach (['Conforme', 'Relancer les vérifications', 'Version de PHP'] as $s) {
            $this->assertTrue(str_contains($b, $s), $s);
        }
    }

    public function testPrerequisiteStatuses(): void
    {
        $old = (new PrerequisiteChecker($this->root, false, '8.1.0'))->run();
        $php = array_values(array_filter($old, fn ($c) => $c['id'] === 'php'))[0];
        $this->assertSame('incompatible', $php['status']);
        $this->assertTrue(!PrerequisiteChecker::canProceed($old));
        chmod($this->root . '/storage/logs', 0555);
        if (posix_geteuid() !== 0) {
            $c = (new PrerequisiteChecker($this->root, true))->run();
            $this->assertTrue(!PrerequisiteChecker::canProceed($c), 'dossier non inscriptible détecté');
        }
        chmod($this->root . '/storage/logs', 0777);
        $http = array_values(array_filter((new PrerequisiteChecker($this->root, false))->run(), fn ($c) => $c['id'] === 'https'))[0];
        $this->assertSame('warning', $http['status']);
    }

    public function testDatabaseTestPermissionsAndSave(): void
    {
        $this->authorize();
        $r = $this->post('/install/database', $this->dbForm(['action' => 'test']));
        $this->assertTrue(str_contains($r->body, 'Connexion réussie'));
        $this->assertTrue(!str_contains($r->body, Env::get('TEST_DB_PASSWORD')), 'mot de passe renvoyé au navigateur');
        $this->assertTrue(!is_file($this->root . '/.env'), 'test ne doit rien écrire');

        $r = $this->post('/install/database', $this->dbForm(['action' => 'perms']));
        $this->assertTrue(str_contains($r->body, 'Privilège CREATE : OK') && str_contains($r->body, 'Privilège DROP : OK'));
        $this->assertSame(0, count($this->pdo->query("SHOW TABLES LIKE 'rvm_perm_%'")->fetchAll()), 'tables temporaires nettoyées');

        $r = $this->post('/install/database', $this->dbForm(['action' => 'save']));
        $this->assertTrue(str_contains($r->body, 'Configuration enregistrée'));
        $this->assertSame(0600, fileperms($this->root . '/.env') & 0777);
        $env = (string) file_get_contents($this->root . '/.env');
        $this->assertTrue(str_contains($env, 'DB_NAME=' . Env::get('TEST_DB_NAME')));
    }

    public function testBadCredentialsFailWithoutLeakingPassword(): void
    {
        $this->authorize();
        $r = $this->post('/install/database', $this->dbForm(['action' => 'test', 'password' => 'Mauvais-Secret-42']));
        $this->assertTrue(str_contains($r->body, 'Accès refusé'));
        $this->assertTrue(!str_contains($r->body, 'Mauvais-Secret-42'));
        $this->assertTrue(!is_file($this->root . '/.env'));
    }

    public function testInvalidInputRejected(): void
    {
        [$cfg, $err] = DatabaseSetup::normalize(['type' => 'oracle', 'host' => 'a b;c', 'port' => 99999, 'name' => 'x;DROP', 'user' => '', 'ssl_mode' => 'nope', 'ssl_ca' => '/nonexistent']);
        $this->assertTrue(count($err) >= 6, 'erreurs : ' . count($err));
    }

    public function testEnvMergePreservesOtherKeys(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV=production\nDB_HOST=old\n# note\n");
        $setup = new DatabaseSetup($this->root . '/.env', '');
        [$cfg] = DatabaseSetup::normalize($this->dbForm(['password' => 'p@ss "w d']));
        $setup->writeEnv($cfg);
        $parsed = Env::parse((string) file_get_contents($this->root . '/.env'));
        $this->assertSame('production', $parsed['APP_ENV']);
        $this->assertSame(Env::get('TEST_DB_HOST', '127.0.0.1'), $parsed['DB_HOST']);
        $this->assertSame('p@ss "w d', $parsed['DB_PASSWORD'], 'mot de passe avec caractères spéciaux');
    }

    public function testForeignDatabaseIsNeverTouched(): void
    {
        $this->pdo->exec('CREATE TABLE precious (id INT)');
        $this->pdo->exec('INSERT INTO precious VALUES (1)');
        $this->authorize();
        $r = $this->post('/install/database', $this->dbForm(['action' => 'save']));
        $this->assertTrue(str_contains($r->body, 'enregistrement refusé'));
        $this->assertTrue(!is_file($this->root . '/.env'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM precious')->fetchColumn());
        $setup = new DatabaseSetup($this->root . '/.env', dirname(__DIR__, 2) . '/database/migrations');
        try {
            $setup->migrate($this->pdo, Env::get('TEST_DB_NAME'));
            $this->assertTrue(false, 'exception attendue');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM precious')->fetchColumn());
    }

    public function testFullInstallationFlowThenLock(): void
    {
        $this->authorize();
        $this->post('/install/database', $this->dbForm(['action' => 'save']));

        $v = $this->get('/install/verify');
        $this->assertTrue(str_contains($v->body, 'Base vide'));
        $this->assertSame(302, $this->get('/install/admin')->status, 'admin refusé avant migration');
        $this->assertTrue(str_contains($this->post('/install/verify', ['action' => 'migrate'])->body, 'Migrations appliquées'));
        $this->assertSame(200, $this->get('/install/finish')->status);

        // finalisation refusée tant qu'il n'y a pas d'administrateur
        $this->assertSame(409, $this->post('/install/finish', [])->status);
        $this->assertTrue(!$this->lock->isInstalled());

        $good = ['last_name' => 'Durand', 'first_name' => 'Léa', 'username' => 'ldurand', 'email' => 'lea@example.org',
            'password' => 'Correct-Horse-9', 'password_confirm' => 'Correct-Horse-9'];
        $this->assertTrue(str_contains($this->post('/install/admin', ['password' => 'faible', 'password_confirm' => 'autre'] + $good)->body, 'Au moins 12'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertTrue(str_contains($this->post('/install/admin', $good)->body, 'Administrateur créé'));
        $row = $this->pdo->query('SELECT u.password_hash, r.code FROM users u JOIN roles r ON r.id=u.role_id')->fetch();
        $this->assertSame('admin', $row['code']);
        $this->assertTrue(str_starts_with($row['password_hash'], '$argon2id$') || str_starts_with($row['password_hash'], '$2y$'));
        // un second administrateur ne peut pas être créé par l'assistant
        $this->post('/install/admin', ['username' => 'autre', 'email' => 'x@example.org'] + $good);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $this->assertTrue(str_contains($this->get('/install/services')->body, 'Non configuré'));
        $fin = $this->post('/install/finish', []);
        $this->assertSame(302, $fin->status);
        $this->assertSame('/login', $fin->headers['Location']);
        $this->assertTrue($this->lock->isInstalled());

        foreach (['/install', '/install/database', '/install/admin'] as $p) {
            $this->assertSame(404, $this->get($p)->status, "$p doit être verrouillé");
        }
        $this->assertSame(404, $this->post('/install/token', ['token' => 'x'], false)->status);
        $this->assertTrue(!$this->lock->consume('x'));
        try {
            $this->lock->issueToken();
            $this->assertTrue(false);
        } catch (\RuntimeException) {
        }
    }

    public function testResumeExistingInstallationPreservesData(): void
    {
        (new \App\Core\Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->pdo->exec("INSERT INTO users (role_id, username, email, first_name, last_name, password_hash) VALUES (3,'keep','k@example.org','K','K','h')");
        $this->authorize();
        $this->post('/install/database', $this->dbForm(['action' => 'save']));
        $this->assertTrue(str_contains($this->get('/install/verify')->body, 'données sont préservées'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testXssEscaped(): void
    {
        $this->authorize();
        $r = $this->post('/install/database', $this->dbForm(['action' => 'test', 'user' => '<script>alert(1)</script>']));
        $this->assertTrue(!str_contains($r->body, '<script>alert(1)</script>'));
    }
}
