<?php
declare(strict_types=1);

namespace Installer;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use PDO;

final class InstallerController
{
    public const STEPS = [1 => 'Bienvenue', 2 => 'Prérequis', 3 => 'Base de données', 4 => 'Vérification', 5 => 'Administrateur', 6 => 'Services', 7 => 'Finalisation'];

    public function __construct(
        private InstallationLock $lock,
        private DatabaseSetup $db,
        private PrerequisiteChecker $pre,
        private SessionStore $session,
        private string $viewsDir,
    ) {
    }

    public function handle(Request $req): Response
    {
        if ($this->lock->isInstalled()) {
            return new Response(404, 'Introuvable.', ['Content-Type' => 'text/plain; charset=utf-8']); // assistant verrouillé
        }
        $path = rtrim($req->path, '/');
        if ($req->method === 'POST') {
            $expected = (string) $this->session->get('csrf', '');
            $tokenFirstVisit = $expected === '' && $path === '/install/token'; // le jeton d'installation est lui-même le secret
            if (!$tokenFirstVisit && ($expected === '' || !hash_equals($expected, (string) ($req->form['_csrf'] ?? '')))) {
                return new Response(403, 'Jeton CSRF invalide.', ['Content-Type' => 'text/plain; charset=utf-8']);
            }
        }
        if ($this->session->get('csrf') === null) {
            $this->session->set('csrf', bin2hex(random_bytes(32)));
        }
        if ($path === '/install/token' && $req->method === 'POST') {
            return $this->token($req);
        }
        if (!$this->session->get('authorized', false)) {
            return $path === '/install' ? $this->tokenPage() : $this->redirect('/install');
        }
        return match ($path) {
            '/install' => $this->welcome(),
            '/install/prerequisites' => $this->prerequisites(),
            '/install/database' => $this->database($req),
            '/install/verify' => $this->verify($req),
            '/install/admin' => $this->admin($req),
            '/install/services' => $this->services(),
            '/install/finish' => $this->finish($req),
            default => new Response(404, 'Introuvable.', ['Content-Type' => 'text/plain; charset=utf-8']),
        };
    }

    // ---- étape 0 : jeton -------------------------------------------------

    private function tokenPage(?string $error = null): Response
    {
        return $this->render('token', ['error' => $error, 'hasToken' => $this->lock->hasValidToken()], 0, $error ? 401 : 200);
    }

    private function token(Request $req): Response
    {
        if ($this->lock->consume((string) ($req->form['token'] ?? ''))) {
            $this->session->regenerate();
            $this->session->set('authorized', true);
            $this->session->set('csrf', bin2hex(random_bytes(32)));
            return $this->redirect('/install');
        }
        return $this->tokenPage('Jeton invalide, expiré ou déjà utilisé.');
    }

    // ---- étape 1 : bienvenue ---------------------------------------------

    private function welcome(): Response
    {
        return $this->render('welcome', ['system' => [
            'PHP' => PHP_VERSION, 'Système' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'Serveur web' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
            'Pilotes PDO' => implode(', ', PDO::getAvailableDrivers()),
        ]], 1);
    }

    // ---- étape 2 : prérequis ---------------------------------------------

    private function prerequisites(): Response
    {
        $c = $this->pre->run();
        return $this->render('prerequisites', ['checks' => $c, 'can' => PrerequisiteChecker::canProceed($c)], 2);
    }

    private function prereqsOk(): bool
    {
        return PrerequisiteChecker::canProceed($this->pre->run());
    }

    // ---- étape 3 : base de données ---------------------------------------

    private function database(Request $req): Response
    {
        if (!$this->prereqsOk()) {
            return $this->redirect('/install/prerequisites');
        }
        $saved = $this->session->get('db_pending') ?? $this->db->loadConfig();
        $values = $saved ?? ['type' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'rendezvous_manager', 'user' => '', 'ssl_mode' => 'verify', 'ssl_ca' => ''];
        $result = null;

        if ($req->method === 'POST') {
            $in = $req->form;
            if (($in['password'] ?? '') === '' && $saved !== null) {
                $in['password'] = $saved['password']; // mot de passe jamais renvoyé au navigateur : on réutilise celui du serveur
            }
            [$cfg, $errors] = DatabaseSetup::normalize($in);
            $values = $cfg;
            $action = (string) ($req->form['action'] ?? 'test');
            $result = ['errors' => $errors, 'lines' => [], 'saved' => false];
            if (!$errors) {
                $this->session->set('db_pending', $cfg);
                $result = $this->runDatabaseAction($action, $cfg, $result);
            }
        }
        unset($values['password']);
        return $this->render('database', ['v' => $values, 'result' => $result, 'hasPassword' => $saved !== null], 3);
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array{errors:list<string>,lines:list<array{ok:bool,text:string}>,saved:bool} $res
     * @return array{errors:list<string>,lines:list<array{ok:bool,text:string}>,saved:bool}
     */
    private function runDatabaseAction(string $action, array $cfg, array $res): array
    {
        $c = $this->db->connect($cfg);
        $res['lines'][] = ['ok' => $c['ok'], 'text' => $c['message']];
        if (!$c['ok']) {
            return $res;
        }
        $v = DatabaseSetup::versionCheck($c['version'], $cfg['type']);
        $res['lines'][] = ['ok' => $v['ok'], 'text' => 'Serveur ' . $c['version'] . ' : ' . $v['message']];
        if ($cfg['ssl_mode'] === 'disabled') {
            $res['lines'][] = ['ok' => true, 'text' => 'Avertissement : connexion non chiffrée (TLS désactivé).'];
        }
        if ($action === 'test') {
            return $res;
        }
        $perms = $this->db->checkPermissions($c['pdo']);
        $permsOk = true;
        foreach ($perms as $p) {
            $permsOk = $permsOk && $p['ok'];
            $res['lines'][] = ['ok' => $p['ok'], 'text' => 'Privilège ' . $p['privilege'] . ' : ' . $p['message']];
        }
        if ($action === 'save' && $v['ok'] && $permsOk) {
            $state = $this->db->inspect($c['pdo'], $cfg['name'])['state'];
            if ($state === 'foreign') {
                $res['lines'][] = ['ok' => false, 'text' => 'La base contient des tables qui ne viennent pas de RendezVous Manager : enregistrement refusé (rien n\'est supprimé). Utilisez une base vide.'];
                return $res;
            }
            $this->db->writeEnv($cfg);
            $this->session->forget('db_pending');
            $res['saved'] = true;
            $res['lines'][] = ['ok' => true, 'text' => 'Configuration enregistrée dans .env (permissions 0600, hors zone publique).'];
        }
        return $res;
    }

    /** @return array{cfg:array<string,mixed>,pdo:PDO}|null */
    private function dbContext(): ?array
    {
        $cfg = $this->db->loadConfig();
        if ($cfg === null) {
            return null;
        }
        $c = $this->db->connect($cfg);
        return $c['ok'] ? ['cfg' => $cfg, 'pdo' => $c['pdo'], 'version' => $c['version']] : null;
    }

    // ---- étape 4 : vérification de la base --------------------------------

    private function verify(Request $req): Response
    {
        $ctx = $this->dbContext();
        if ($ctx === null) {
            return $this->redirect('/install/database');
        }
        $msg = null;
        if ($req->method === 'POST' && ($req->form['action'] ?? '') === 'migrate') {
            try {
                $done = $this->db->migrate($ctx['pdo'], $ctx['cfg']['name']);
                $msg = ['ok' => true, 'text' => $done ? 'Migrations appliquées : ' . implode(', ', $done) : 'Base déjà à jour.'];
            } catch (\Throwable $e) {
                $msg = ['ok' => false, 'text' => $e->getMessage()];
            }
        }
        $i = $this->db->inspect($ctx['pdo'], $ctx['cfg']['name']);
        return $this->render('verify', [
            'info' => $i, 'version' => $ctx['version'], 'vcheck' => DatabaseSetup::versionCheck($ctx['version'], $ctx['cfg']['type']),
            'msg' => $msg, 'ready' => $i['state'] !== 'foreign' && !$i['pending'] && !$i['unknown_applied'],
            'dbName' => $ctx['cfg']['name'],
        ], 4);
    }

    private function dbReady(?array $ctx): bool
    {
        if ($ctx === null) {
            return false;
        }
        $i = $this->db->inspect($ctx['pdo'], $ctx['cfg']['name']);
        return $i['state'] === 'compatible' && !$i['pending'] && !$i['unknown_applied'];
    }

    private function adminExists(PDO $pdo): bool
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1")->fetchColumn() > 0;
    }

    // ---- étape 5 : administrateur -------------------------------------------

    private function admin(Request $req): Response
    {
        $ctx = $this->dbContext();
        if (!$this->dbReady($ctx)) {
            return $this->redirect('/install/verify');
        }
        $exists = $this->adminExists($ctx['pdo']);
        $errors = [];
        $v = ['last_name' => '', 'first_name' => '', 'username' => '', 'email' => ''];
        $created = false;
        if (!$exists && $req->method === 'POST') {
            $f = $req->form;
            foreach ($v as $k => $_) {
                $v[$k] = trim((string) ($f[$k] ?? ''));
            }
            $pw = (string) ($f['password'] ?? '');
            if ($v['last_name'] === '' || mb_strlen($v['last_name']) > 80) {
                $errors[] = 'Nom requis (80 caractères maximum).';
            }
            if ($v['first_name'] === '' || mb_strlen($v['first_name']) > 80) {
                $errors[] = 'Prénom requis (80 caractères maximum).';
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $v['username'])) {
                $errors[] = 'Identifiant : 3 à 60 caractères (lettres, chiffres, . _ -).';
            }
            if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190) {
                $errors[] = 'Adresse e-mail invalide.';
            }
            if ($pw !== (string) ($f['password_confirm'] ?? '')) {
                $errors[] = 'La confirmation ne correspond pas au mot de passe.';
            }
            array_push($errors, ...PasswordHasher::validateStrength($pw, 12, $v['username']));
            if (!$errors) {
                try {
                    $id = (new UserRepository($ctx['pdo']))->create([
                        'role_code' => 'admin', 'username' => $v['username'], 'email' => $v['email'],
                        'first_name' => $v['first_name'], 'last_name' => $v['last_name'],
                        'password_hash' => PasswordHasher::hash($pw), 'timezone' => (string) Config::get('app.timezone', 'Europe/Paris'),
                    ]);
                    (new \App\Repositories\CalendarRepository($ctx['pdo']))->ensureDefault($id, (string) Config::get('app.timezone', 'Europe/Paris'));
                    (new AuditLogger($ctx['pdo']))->log('install.admin_created', $id, 'user', $id, null);
                    $created = $exists = true;
                } catch (\PDOException) {
                    $errors[] = 'Cet identifiant ou cette adresse e-mail existe déjà.';
                }
            }
        }
        return $this->render('admin', ['errors' => $errors, 'v' => $v, 'exists' => $exists, 'created' => $created], 5);
    }

    // ---- étape 6 : services ---------------------------------------------------

    /** @return list<array{id:string,label:string,status:string,message:string,required:bool}> */
    private function serviceChecks(): array
    {
        $all = $this->pre->run();
        $pick = array_values(array_filter($all, fn ($c) => in_array($c['id'], ['https', 'cron', 'dir_logs', 'dir_exports', 'dir_backups'], true)));
        $push = Config::get('notifications.push.public_key', '') !== '' && Config::get('notifications.push.private_key', '') !== '';
        $mail = (bool) Config::get('notifications.email.enabled', false) && Config::get('notifications.email.host', '') !== '';
        $pick[] = ['id' => 'push', 'label' => 'Notifications Web Push', 'status' => $push ? PrerequisiteChecker::OK : PrerequisiteChecker::OPTIONAL,
            'message' => $push ? 'Clés VAPID présentes (envoi non testé)' : 'Non configuré : clés VAPID absentes (voir docs/notifications.md)', 'required' => false];
        $pick[] = ['id' => 'mail', 'label' => 'E-mail SMTP', 'status' => $mail ? PrerequisiteChecker::OK : PrerequisiteChecker::OPTIONAL,
            'message' => $mail ? 'Paramètres présents (envoi non testé)' : 'Non configuré : canal e-mail désactivé', 'required' => false];
        $pick[] = ['id' => 'sync', 'label' => 'Synchronisation', 'status' => PrerequisiteChecker::OPTIONAL, 'message' => 'Fournie par l\'API (phase 11), non encore livrée', 'required' => false];
        $pick[] = ['id' => 'backup', 'label' => 'Sauvegardes automatiques', 'status' => PrerequisiteChecker::OPTIONAL, 'message' => 'Non configurées (phase 17) : planifier scripts/backup.php', 'required' => false];
        return $pick;
    }

    private function services(): Response
    {
        $ctx = $this->dbContext();
        if (!$this->dbReady($ctx) || !$this->adminExists($ctx['pdo'])) {
            return $this->redirect('/install/admin');
        }
        return $this->render('services', ['checks' => $this->serviceChecks()], 6);
    }

    // ---- étape 7 : finalisation ---------------------------------------------

    private function finish(Request $req): Response
    {
        $ctx = $this->dbContext();
        $summary = [
            ['label' => 'Prérequis indispensables', 'ok' => $this->prereqsOk()],
            ['label' => 'Connexion et schéma de la base à jour', 'ok' => $this->dbReady($ctx)],
            ['label' => 'Compte administrateur créé', 'ok' => $ctx !== null && $this->adminExists($ctx['pdo'])],
        ];
        $ok = !in_array(false, array_column($summary, 'ok'), true);
        if ($req->method === 'POST') {
            if (!$ok) {
                return $this->render('finish', ['summary' => $summary, 'ok' => false, 'error' => 'Les étapes indispensables ne sont pas toutes réussies.'], 7, 409);
            }
            (new AuditLogger($ctx['pdo']))->log('install.completed', null, null, null, $req->ip);
            $this->lock->lock();
            $this->session->destroy();
            return $this->redirect('/login');
        }
        return $this->render('finish', ['summary' => $summary, 'ok' => $ok, 'error' => null], 7);
    }

    // ---- utilitaires -----------------------------------------------------------

    private function redirect(string $to): Response
    {
        return new Response(302, '', ['Location' => $to]);
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data, int $step, int $status = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $data += ['e' => static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'csrf' => (string) $this->session->get('csrf'), 'step' => $step, 'steps' => self::STEPS,
            'version' => (string) Config::get('app.version', ''), 'nonce' => $nonce, 'labels' => PrerequisiteChecker::LABELS];
        $viewsDir = $this->viewsDir;
        $body = (static function () use ($viewsDir, $view, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $viewsDir . '/' . $view . '.php';
            $content = (string) ob_get_clean();
            ob_start();
            include $viewsDir . '/layout.php';
            return (string) ob_get_clean();
        })();
        return new Response($status, $body, [
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
