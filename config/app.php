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
    'auth'     => [
        'cookie_name'       => 'rvm_session',
        'cookie_secure'     => Env::bool('COOKIE_SECURE', Env::get('APP_ENV', 'production') === 'production'),
        'session_lifetime'  => 12 * 3600, // durée absolue (secondes)
        'session_idle'      => 2 * 3600,  // inactivité maximale
        'max_failures'      => 5,         // par identifiant
        'max_failures_ip'   => 20,        // par adresse IP
        'throttle_window'   => 900,       // fenêtre de comptage (secondes)
        'min_password'      => 12,
    ],
    'paths'    => [
        'root'    => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'logs'    => dirname(__DIR__) . '/storage/logs',
        'lock'    => dirname(__DIR__) . '/storage/install.lock',
    ],
];
