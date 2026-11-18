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
        'ca_file'    => Env::get('MAIL_CA_FILE', ''),
        'timeout'    => 15,
    ],
    'push' => [
        'public_key'  => Env::get('VAPID_PUBLIC_KEY', ''),
        'private_key' => Env::get('VAPID_PRIVATE_KEY', ''),
        'subject'     => Env::get('VAPID_SUBJECT', ''),
        'ttl'         => 3600, // durée de conservation par le service push si l'appareil est éteint (secondes)
        'timeout'     => 10,
        'max_failures' => 5,   // échecs permanents consécutifs avant suppression de l'abonnement
        'max_subscriptions' => 10,
        // Services push reconnus (SSRF) ; PUSH_ALLOW_ANY=1 uniquement pour le développement et les tests.
        'allow_any'   => Env::bool('PUSH_ALLOW_ANY', false),
        'allowed_hosts' => ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
            'web.push.apple.com', 'push.apple.com', 'notify.windows.com', 'wns.windows.com'],
    ],
];
