<?php
declare(strict_types=1);

// Lanceur de tests minimal, sans dépendance. Usage : php tests/run.php [filtre]
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? '';
$pass = $fail = $skip = 0;
foreach (glob(__DIR__ . '/{Unit,Integration,Security}/*Test.php', GLOB_BRACE) ?: [] as $file) {
    if ($filter !== '' && !str_contains($file, $filter)) {
        continue;
    }
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    $t = new $class();
    foreach (get_class_methods($t) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        try {
            $t->setUp();
            $t->$method();
            $pass++;
            echo "  ok    $class::$method\n";
        } catch (Tests\SkipTest $e) {
            $skip++;
            echo "  SKIP  $class::$method ({$e->getMessage()})\n";
        } catch (Throwable $e) {
            $fail++;
            echo "  FAIL  $class::$method : {$e->getMessage()}\n";
        }
    }
}
echo "\n$pass réussis, $fail échecs, $skip ignorés\n";
exit($fail > 0 ? 1 : 0);
