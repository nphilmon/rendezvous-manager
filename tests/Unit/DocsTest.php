<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use Tests\TestCase;

/** La documentation ne peut pas dériver du code : routes, variables d'environnement, liens et index du README sont vérifiés. */
final class DocsTest extends TestCase
{
    private string $root;
    private const DOCS = ['installation.md', 'configuration_mysql.md', 'phpmyadmin.md', 'api.md', 'securite.md', 'utilisation.md', 'notifications.md', 'deploiement.md'];

    public function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        return (string) file_get_contents($this->root . '/' . $rel);
    }

    public function testRequiredDocumentsExistAndAreSubstantial(): void
    {
        foreach (self::DOCS as $d) {
            $this->assertTrue(is_file($this->root . '/docs/' . $d) && strlen($this->read('docs/' . $d)) > 1500, "docs/$d manquant ou trop court");
        }
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        $router = new Router();
        (require $this->root . '/api/routes.php')($router);
        $api = $this->read('docs/api.md');
        $missing = [];
        foreach ($router->routes() as [$m, $p]) {
            if (str_starts_with($p, '/api/') && !str_contains($api, "`$m $p`")) {
                $missing[] = "$m $p";
            }
        }
        $this->assertSame([], $missing, 'routes absentes de docs/api.md : ' . implode(', ', $missing));
        // et inversement : aucune route documentée qui n'existe plus
        preg_match_all('/`((?:GET|POST|PUT|DELETE) \/api\/v1\/[^`]+)`/', $api, $m);
        $known = array_map(static fn ($r) => "$r[0] $r[1]", $router->routes());
        $ghost = array_values(array_diff(array_unique($m[1]), $known));
        $this->assertSame([], $ghost, 'routes documentées mais inexistantes : ' . implode(', ', $ghost));
    }

    public function testEveryEnvironmentVariableIsExplained(): void
    {
        $docs = $this->read('docs/installation.md') . $this->read('docs/configuration_mysql.md');
        $missing = [];
        foreach (explode("\n", $this->read('.env.example')) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]+)=/', $line, $m) && !str_contains($docs, $m[1]) && !str_starts_with($m[1], 'TEST_DB_')) {
                $missing[] = $m[1];
            }
        }
        $this->assertSame([], $missing, 'variables non documentées : ' . implode(', ', $missing));
        $this->assertTrue(str_contains($this->read('docs/installation.md'), 'TEST_DB_'), 'TEST_DB_* expliqué');
    }

    public function testRelativeLinksResolveAndReadmeIndexesEveryDocument(): void
    {
        $broken = [];
        foreach (array_merge(['README.md'], array_map(static fn ($d) => 'docs/' . $d, self::DOCS)) as $file) {
            preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', $this->read($file), $m);
            foreach ($m[1] as $link) {
                if (preg_match('#^(https?:|mailto:)#', $link)) {
                    continue;
                }
                $target = $this->root . '/' . (str_starts_with($file, 'docs/') ? 'docs/' : '') . $link;
                if (!file_exists($target)) {
                    $broken[] = "$file → $link";
                }
            }
        }
        $this->assertSame([], $broken, 'liens cassés : ' . implode(', ', $broken));
        $readme = $this->read('README.md');
        foreach (self::DOCS as $d) {
            $this->assertTrue(str_contains($readme, "docs/$d"), "le README doit référencer docs/$d");
        }
    }

    public function testDocumentedCommandsAndFilesExist(): void
    {
        $text = $this->read('docs/installation.md') . $this->read('docs/configuration_mysql.md') . $this->read('docs/securite.md') . $this->read('docs/utilisation.md');
        preg_match_all('#\b((?:scripts|workers|tests)/[A-Za-z0-9_./-]+\.(?:php|sh|js))\b#', $text, $m);
        $missing = array_values(array_filter(array_unique($m[1]), fn ($f) => !is_file($this->root . '/' . $f)));
        $this->assertSame([], $missing, 'fichiers cités mais absents : ' . implode(', ', $missing));
        $this->assertTrue(count(array_unique($m[1])) >= 6, 'les scripts principaux sont cités');
    }

    public function testReadmeMakesNoKnownFalseClaims(): void
    {
        $readme = $this->read('README.md');
        foreach (['Sauvegardes chiffrées', 'FullCalendar (licences', 'Tailwind (CDN', 'CORS restrictif'] as $stale) {
            $this->assertTrue(!str_contains($readme, $stale), "affirmation obsolète dans le README : $stale");
        }
    }
}
