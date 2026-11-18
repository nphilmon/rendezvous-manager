<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'driver'   => Env::get('DB_DRIVER', 'mysql'),
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::int('DB_PORT', 3306),
    'name'     => Env::get('DB_NAME', 'rendezvous_manager'),
    'user'     => Env::get('DB_USER', ''),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    // disabled | required | verify
    'ssl_mode' => Env::get('DB_SSL_MODE', 'verify'),
    'ssl_ca'   => Env::get('DB_SSL_CA', ''),
];
