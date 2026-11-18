<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use PDO;

final class AuthService
{
    public function __construct(
        private PDO $pdo,
        private UserRepository $users,
        private AuditLogger $audit,
    ) {
    }

    private function cfg(string $k, mixed $d = null): mixed
    {
        return Config::get('app.auth.' . $k, $d);
    }

    /**
     * @return array{user:AuthUser, token:string, csrf:string, expires_at:int}
     * @throws HttpException 401 identifiants invalides (message identique pour tous les cas d'échec), 429 trop de tentatives
     */
    public function login(string $identifier, string $password, string $ip, string $ua): array
    {
        $identifier = trim($identifier);
        $this->assertNotThrottled(mb_strtolower($identifier), $ip);

        $row = $this->users->findByLogin($identifier);
        $hash = $row['password_hash'] ?? PasswordHasher::dummyHash();
        $valid = PasswordHasher::verify($password, $hash)
            && $row !== null
            && (int) $row['is_active'] === 1
            && ($row['locked_until'] === null || strtotime($row['locked_until'] . ' UTC') <= time());

        $this->recordAttempt(mb_strtolower($identifier), $ip, $valid);
        if (!$valid) {
            $this->audit->log('auth.login_failed', $row ? (int) $row['id'] : null, 'user', null, $ip, ['identifier' => $identifier]);
            throw new HttpException(401, 'Identifiant ou mot de passe incorrect.');
        }

        if (PasswordHasher::needsRehash($row['password_hash'])) {
            $this->users->updatePasswordHash((int) $row['id'], PasswordHasher::hash($password));
        }

        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + (int) $this->cfg('session_lifetime', 43200);
        $this->pdo->prepare(
            'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, csrf_token, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token), $row['id'], $ip, mb_substr($ua, 0, 255), $csrf,
            gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $expires),
        ]);
        $this->pdo->prepare('UPDATE users SET last_login_at = ?, failed_logins = 0 WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', $now), $row['id']]);

        $user = $this->users->toAuthUser($row);
        $this->audit->log('auth.login', $user->id, 'user', $user->id, $ip);
        return ['user' => $user, 'token' => $token, 'csrf' => $csrf, 'expires_at' => $expires];
    }

    /** @return array{user:AuthUser, csrf:string, session_id:string}|null */
    public function authenticate(?string $token): ?array
    {
        if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $id = hash('sha256', $token);
        $st = $this->pdo->prepare('SELECT * FROM user_sessions WHERE id = ? AND revoked_at IS NULL');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) {
            return null;
        }
        $now = time();
        $idle = (int) $this->cfg('session_idle', 7200);
        if (strtotime($s['expires_at'] . ' UTC') <= $now || strtotime($s['last_seen_at'] . ' UTC') + $idle <= $now) {
            return null;
        }
        $user = $this->users->findAuthUser((int) $s['user_id']);
        if ($user === null) {
            return null; // compte désactivé ou supprimé
        }
        if (strtotime($s['last_seen_at'] . ' UTC') < $now - 60) {
            $this->pdo->prepare('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), $id]);
        }
        return ['user' => $user, 'csrf' => $s['csrf_token'], 'session_id' => $id];
    }

    public function logout(string $sessionId, AuthUser $user, string $ip): void
    {
        $this->pdo->prepare('UPDATE user_sessions SET revoked_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $sessionId]);
        $this->audit->log('auth.logout', $user->id, 'user', $user->id, $ip);
    }

    /** @throws HttpException 422 si le nouveau mot de passe est faible, 403 si l'ancien est faux */
    public function changePassword(AuthUser $user, string $sessionId, string $current, string $new, string $ip): void
    {
        $hash = $this->users->passwordHash($user->id);
        if ($hash === null || !PasswordHasher::verify($current, $hash)) {
            $this->audit->log('auth.password_change_failed', $user->id, 'user', $user->id, $ip);
            throw new HttpException(403, 'Mot de passe actuel incorrect.');
        }
        $errors = PasswordHasher::validateStrength($new, (int) $this->cfg('min_password', 12), $user->username);
        if ($errors) {
            throw new HttpException(422, 'Mot de passe trop faible.', ['password' => $errors]);
        }
        $this->users->setPassword($user->id, PasswordHasher::hash($new), false);
        // Révoque toutes les autres sessions de l'utilisateur.
        $this->pdo->prepare('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), $user->id, $sessionId]);
        $this->audit->log('auth.password_changed', $user->id, 'user', $user->id, $ip);
    }

    private function assertNotThrottled(string $identifier, string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - (int) $this->cfg('throttle_window', 900));
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND succeeded = 0 AND attempted_at >= ?');
        $q->execute([$identifier, $since]);
        $byId = (int) $q->fetchColumn();
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND succeeded = 0 AND attempted_at >= ?');
        $q->execute([$ip, $since]);
        $byIp = (int) $q->fetchColumn();
        if ($byId >= (int) $this->cfg('max_failures', 5) || $byIp >= (int) $this->cfg('max_failures_ip', 20)) {
            throw new HttpException(429, 'Trop de tentatives. Réessayez plus tard.', ['retry_after' => (int) $this->cfg('throttle_window', 900)]);
        }
    }

    private function recordAttempt(string $identifier, string $ip, bool $ok): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, succeeded, attempted_at) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($identifier, 0, 190), $ip, (int) $ok, gmdate('Y-m-d H:i:s')]);
        if ($ok) {
            // une connexion réussie remet le compteur de cet identifiant à zéro
            $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND succeeded = 0')->execute([$identifier]);
        }
    }
}
