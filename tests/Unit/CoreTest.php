<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use Tests\TestCase;

final class CoreTest extends TestCase
{
    public function testEnvParse(): void
    {
        $v = Env::parse("# c\nA=1\nB=\"x y\"\nbad line\nc=low\n");
        $this->assertSame(['A' => '1', 'B' => 'x y'], $v);
    }

    public function testRouterParamsAnd404And405(): void
    {
        $r = new Router();
        $r->add('GET', '/api/v1/items/{id}', fn ($q, $p) => Response::json($p));
        $res = $r->dispatch(new Request('GET', '/api/v1/items/42'));
        $this->assertSame('{"id":"42"}', $res->body);
        foreach ([['POST', '/api/v1/items/1', 405], ['GET', '/nope', 404]] as [$m, $p, $code]) {
            try {
                $r->dispatch(new Request($m, $p));
                $this->assertTrue(false, 'exception attendue');
            } catch (HttpException $e) {
                $this->assertSame($code, $e->status);
            }
        }
    }

    public function testSplitStatements(): void
    {
        $s = Migrator::splitStatements("-- c\nCREATE TABLE a (\n x INT\n);\nINSERT INTO a VALUES (1);\n");
        $this->assertSame(2, count($s));
    }

    public function testLoggerRedactsSecrets(): void
    {
        $r = Logger::redact(['user' => 'a', 'password' => 'x', 'n' => ['api_token' => 'y']]);
        $this->assertSame('[masqué]', $r['password']);
        $this->assertSame('[masqué]', $r['n']['api_token']);
        $this->assertSame('a', $r['user']);
    }

    public function testRequestInvalidJson(): void
    {
        try {
            (new Request('POST', '/x', [], '{bad'))->json();
            $this->assertTrue(false);
        } catch (HttpException $e) {
            $this->assertSame(400, $e->status);
        }
    }
}
