<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => 'RendezVous Manager',
    'version'  => '4.0.0-dev',
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => Env::get('APP_URL', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Paris'),
    'paths'    => [
        'root'    => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'logs'    => dirname(__DIR__) . '/storage/logs',
        'lock'    => dirname(__DIR__) . '/storage/install.lock',
    ],
];
