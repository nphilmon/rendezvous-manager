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
    'sync'     => [
        // Intervalle d'actualisation proposé aux navigateurs (secondes) ; borné à 2..300. 30 s en production.
        'interval' => max(2, min(300, Env::int('SYNC_INTERVAL', 30))),
        'max_changes' => 500,
        'retention_days' => 30,
    ],
    'backup'   => [
        'dir'  => Env::get('BACKUP_DIR', '') ?: dirname(__DIR__) . '/storage/backups',
        'keep' => max(1, Env::int('BACKUP_KEEP', 14)), // nombre de sauvegardes conservées
        'key'  => Env::get('BACKUP_KEY', ''),          // clé de chiffrement (base64 de 32 octets) ; vide = sauvegardes non chiffrées
    ],
    'audit'    => [
        'retention_days' => max(0, Env::int('AUDIT_RETENTION_DAYS', 0)), // 0 = conservation illimitée
    ],
    // Strict-Transport-Security : durée en secondes (0 = non envoyé). À activer seulement quand HTTPS est définitivement en place :
    // un navigateur qui l'a reçu refuse ensuite tout accès en HTTP pendant cette durée.
    'hsts_max_age' => max(0, Env::int('HSTS_MAX_AGE', 0)),
    'paths'    => [
        'root'    => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'logs'    => dirname(__DIR__) . '/storage/logs',
        'lock'    => dirname(__DIR__) . '/storage/install.lock',
    ],
];
