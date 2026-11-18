<?php
declare(strict_types=1);

// Autoload Composer si présent, sinon autoloader PSR-4 de secours.
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        $map = ['App\\' => __DIR__ . '/', 'Installer\\' => dirname(__DIR__) . '/installer/'];
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        }
    });
}

use App\Core\Config;
use App\Core\Env;

Env::load(dirname(__DIR__) . '/.env');
Config::init(dirname(__DIR__) . '/config');
date_default_timezone_set('UTC'); // les instants sont toujours manipulés en UTC
mb_internal_encoding('UTF-8');
