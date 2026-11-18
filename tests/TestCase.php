<?php
declare(strict_types=1);

namespace Tests;

final class SkipTest extends \Exception
{
}

abstract class TestCase
{
    public function setUp(): void
    {
    }

    /** Appelé après chaque test, même en cas d'échec. */
    public function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \AssertionError(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
        }
    }

    protected function assertTrue(bool $c, string $msg = 'condition fausse'): void
    {
        if (!$c) {
            throw new \AssertionError($msg);
        }
    }

    protected function skip(string $why): never
    {
        throw new SkipTest($why);
    }
}
