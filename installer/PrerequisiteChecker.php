<?php
declare(strict_types=1);

namespace Installer;

/** Vérifie réellement chaque prérequis ; ne déclare jamais « conforme » sans test. */
final class PrerequisiteChecker
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const INCOMPATIBLE = 'incompatible';
    public const WARNING = 'warning';
    public const OPTIONAL = 'optional';

    public const LABELS = [
        self::OK => 'Conforme', self::MISSING => 'Absent', self::INCOMPATIBLE => 'Incompatible',
        self::WARNING => 'Avertissement', self::OPTIONAL => 'Facultatif',
    ];

    public function __construct(
        private string $root,
        private bool $https = false,
        private string $phpVersion = PHP_VERSION,
    ) {
    }

    /** @return list<array{id:string,label:string,status:string,message:string,required:bool}> */
    public function run(): array
    {
        $r = [];
        $add = static function (string $id, string $label, string $status, string $msg, bool $req = false) use (&$r): void {
            $r[] = ['id' => $id, 'label' => $label, 'status' => $status, 'message' => $msg, 'required' => $req];
        };

        $okPhp = version_compare($this->phpVersion, '8.3.0', '>=');
        $add('php', 'Version de PHP', $okPhp ? self::OK : self::INCOMPATIBLE, "PHP {$this->phpVersion} (8.3 ou supérieur requis)", true);

        foreach (['pdo' => 'PDO', 'pdo_mysql' => 'pdo_mysql', 'mbstring' => 'mbstring', 'openssl' => 'openssl', 'json' => 'json'] as $ext => $label) {
            $has = extension_loaded($ext);
            $add("ext_$ext", "Extension $label", $has ? self::OK : self::MISSING, $has ? 'Chargée' : 'Non chargée', true);
        }
        $add('ext_sodium', 'Extension sodium', extension_loaded('sodium') ? self::OK : self::OPTIONAL, 'Utile au chiffrement des sauvegardes');
        $add('ext_curl', 'Extension curl', extension_loaded('curl') ? self::OK : self::OPTIONAL, 'Utile à l\'envoi Web Push');
        $add('ext_intl', 'Extension intl', extension_loaded('intl') ? self::OK : self::OPTIONAL, 'Utile aux formats de date localisés');
        $add('ext_zip', 'Extension zip', extension_loaded('zip') ? self::OK : self::OPTIONAL, 'Nécessaire à l\'export XLSX');
        $add('argon2id', 'Hachage Argon2id', defined('PASSWORD_ARGON2ID') ? self::OK : self::WARNING,
            defined('PASSWORD_ARGON2ID') ? 'Disponible' : 'Indisponible : bcrypt sera utilisé');

        foreach (['storage/logs' => 'Journaux', 'storage/exports' => 'Exports', 'storage/backups' => 'Sauvegardes', 'storage/sessions' => 'Sessions d\'installation'] as $rel => $label) {
            $p = $this->root . '/' . $rel;
            $w = is_dir($p) && is_writable($p);
            $add('dir_' . basename($rel), "Écriture : $label", $w ? self::OK : self::MISSING, $w ? "$rel accessible en écriture" : "$rel absent ou non inscriptible", true);
        }

        $env = $this->root . '/.env';
        $canEnv = is_file($env) ? is_writable($env) : is_writable($this->root);
        $add('env_write', 'Écriture du fichier .env', $canEnv ? self::OK : self::MISSING, $canEnv ? 'Possible' : 'Impossible : ni .env ni la racine du projet ne sont inscriptibles', true);
        if (is_file($env)) {
            $perm = fileperms($env) & 0777;
            $add('env_perm', 'Permissions de .env', ($perm & 0077) === 0 ? self::OK : self::WARNING, sprintf('%04o', $perm) . (($perm & 0077) === 0 ? '' : ' : devrait être 0600'));
        }
        foreach (['.env', 'config/app.php', 'storage/install.lock'] as $f) {
            // le dossier public ne doit contenir aucun fichier sensible
            if (is_file($this->root . '/public/' . $f)) {
                $add('public_exposure', 'Fichiers sensibles dans public/', self::INCOMPATIBLE, "public/$f ne doit pas exister", true);
            }
        }

        $logOk = $this->canWriteLog();
        $add('log', 'Création de journaux', $logOk ? self::OK : self::MISSING, $logOk ? 'Écriture de test réussie' : 'Écriture de test impossible', true);

        $hasVendor = is_file($this->root . '/vendor/autoload.php');
        $add('composer', 'Dépendances Composer', $hasVendor ? self::OK : self::WARNING,
            $hasVendor ? 'vendor/autoload.php présent' : 'vendor/ absent : l\'autoloader de secours est utilisé (aucune dépendance Composer n\'est requise pour l\'instant)');

        $add('https', 'HTTPS', $this->https ? self::OK : self::WARNING,
            $this->https ? 'Connexion HTTPS détectée' : 'Connexion non chiffrée : à ne pas utiliser en production (cookies Secure, mots de passe en clair sur le réseau)');

        $tz = (string) ini_get('date.timezone');
        $add('timezone', 'Fuseau horaire PHP', $tz !== '' ? self::OK : self::WARNING, $tz !== '' ? "date.timezone = $tz" : 'date.timezone non défini dans php.ini (l\'application travaille en UTC et affiche selon les réglages utilisateur)');

        $hb = $this->root . '/storage/logs/cron_heartbeat';
        $fresh = is_file($hb) && filemtime($hb) > time() - 900;
        $add('cron', 'Tâches cron', $fresh ? self::OK : self::WARNING, $fresh
            ? 'Un worker s\'est exécuté dans les 15 dernières minutes'
            : 'Aucune exécution récente détectée (non vérifiable avant la configuration de cron, voir docs/notifications.md)');

        $assets = ['public/assets/css/app.css', 'public/assets/js/app.js', 'public/assets/js/calendar.js'];
        $missing = array_filter($assets, fn ($a) => !is_file($this->root . '/' . $a));
        $add('frontend', 'Composants frontend', $missing ? self::WARNING : self::OK,
            $missing ? 'Absents : ' . implode(', ', $missing) . ' (interface non encore livrée)' : 'Présents');

        return $r;
    }

    /** @param list<array{status:string,required:bool}> $checks */
    public static function canProceed(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['required'] && in_array($c['status'], [self::MISSING, self::INCOMPATIBLE], true)) {
                return false;
            }
        }
        return true;
    }

    private function canWriteLog(): bool
    {
        $f = $this->root . '/storage/logs/.write_test_' . bin2hex(random_bytes(3));
        $ok = @file_put_contents($f, 'x') !== false;
        @unlink($f);
        return $ok;
    }
}
