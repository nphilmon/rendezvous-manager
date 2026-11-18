<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'max_attempts'  => 5,
    'retry_backoff' => [60, 300, 900, 3600, 21600], // secondes
    'lock_timeout'  => 300,
    'email' => [
        'enabled'    => Env::bool('MAIL_ENABLED', false),
        'host'       => Env::get('MAIL_HOST', ''),
        'port'       => Env::int('MAIL_PORT', 587),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
        'user'       => Env::get('MAIL_USER', ''),
        'password'   => Env::get('MAIL_PASSWORD', ''),
        'from'       => Env::get('MAIL_FROM', ''),
    ],
    'push' => [
        'public_key'  => Env::get('VAPID_PUBLIC_KEY', ''),
        'private_key' => Env::get('VAPID_PRIVATE_KEY', ''),
        'subject'     => Env::get('VAPID_SUBJECT', ''),
    ],
];
