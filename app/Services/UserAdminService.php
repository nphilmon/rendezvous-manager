<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Validation\Rules;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * Administration des comptes (droit « users.manage »). Garde-fous : on ne supprime jamais un compte (désactivation seulement : ses calendriers
 * et rendez-vous restent), on ne peut ni se désactiver ni changer son propre rôle, et le dernier administrateur actif est intouchable.
 * Les mots de passe provisoires ne sont renvoyés qu'une fois, jamais journalisés, et imposent un changement à la prochaine connexion.
 */
final class UserAdminService
{
    private const SORTS = ['name' => 'u.last_name, u.first_name', 'username' => 'u.username', 'role' => 'r.id', 'last_login' => 'u.last_login_at', 'created' => 'u.created_at'];

    public function __construct(private PDO $pdo, private UserRepository $users, private AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function roles(): array
    {
        $rows = $this->pdo->query('SELECT r.id, r.code, r.label, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users,
            (SELECT GROUP_CONCAT(p.code ORDER BY p.code) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id) AS permissions
            FROM roles r ORDER BY r.id')->fetchAll();
        return array_map(static fn ($r) => ['code' => $r['code'], 'label' => $r['label'], 'users' => (int) $r['users'], 'permissions' => $r['permissions'] === null ? [] : explode(',', $r['permissions'])], $rows);
    }

    /** @param array<string,mixed> $f q, role, active (1|0) @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(array $f, string $sort, string $dir, int $page, int $perPage): array
    {
        $w = ['1=1'];
        $args = [];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $f['q'], '\\%_') . '%';
            $w[] = "(u.username LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\' OR u.first_name LIKE ? ESCAPE '\\\\' OR u.last_name LIKE ? ESCAPE '\\\\' OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? ESCAPE '\\\\')";
            array_push($args, $like, $like, $like, $like, $like);
        }
        if (($f['role'] ?? '') !== '') {
            $w[] = 'r.code = ?';
            $args[] = $f['role'];
        }
        if (isset($f['active']) && $f['active'] !== '') {
            $w[] = 'u.is_active = ?';
            $args[] = (int) $f['active'];
        }
        $where = ' WHERE ' . implode(' AND ', $w);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id' . $where);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $d = $dir === 'desc' ? 'DESC' : 'ASC';
        $order = implode(', ', array_map(static fn ($c) => trim($c) . ' ' . $d, explode(',', self::SORTS[$sort] ?? self::SORTS['name']))) . ', u.id ' . $d;
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role_code, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id' . $where
            . " ORDER BY $order LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $st->execute($args);
        return ['rows' => array_map([$this, 'present'], $st->fetchAll()), 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->present($this->row($id));
    }

    /**
     * @param array<string,mixed> $in username, email, first_name, last_name, role, timezone, password (facultatif : généré sinon)
     * @return array{user:array<string,mixed>,temporary_password:string}
     */
    public function create(AuthUser $admin, array $in, string $ip): array
    {
        $e = [];
        $d = $this->validate($in, null, $e);
        $username = Rules::text($in, 'username', 60, true, $e);
        if ($username !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,59}$/', $username)) {
            $e['username'][] = 'Identifiant : 3 à 60 caractères (lettres, chiffres, point, tiret, souligné).';
        }
        $password = isset($in['password']) && $in['password'] !== '' ? $in['password'] : self::generatePassword();
        if (!is_string($password)) {
            $e['password'][] = 'Texte attendu.';
        } elseif ($errs = PasswordHasher::validateStrength($password, 12, (string) $username)) {
            $e['password'] = $errs;
        }
        Rules::throwIf($e);
        $this->assertUnique($username, $d['email'], null);
        try {
            $id = $this->users->create(['role_code' => $d['role'], 'username' => $username, 'email' => $d['email'], 'first_name' => $d['first_name'],
                'last_name' => $d['last_name'], 'password_hash' => PasswordHasher::hash($password), 'timezone' => $d['timezone']]);
        } catch (PDOException $x) {
            throw self::duplicate($x);
        }
        $this->pdo->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?')->execute([$id]);
        if ($d['role'] !== 'readonly') {
            (new \App\Repositories\CalendarRepository($this->pdo))->ensureDefault($id, $d['timezone']);
        }
        $this->audit->log('user.create', $admin->id, 'user', $id, $ip, ['username' => $username, 'role' => $d['role']]);
        return ['user' => $this->get($id), 'temporary_password' => $password];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function update(AuthUser $admin, int $id, array $in, string $ip): array
    {
        $row = $this->row($id);
        $e = [];
        $d = $this->validate($in + ['role' => $row['role_code'], 'timezone' => $row['timezone'], 'email' => $row['email'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name']], $row, $e);
        Rules::throwIf($e);
        if ($id === $admin->id && $d['role'] !== $row['role_code']) {
            throw new HttpException(409, 'Vous ne pouvez pas changer votre propre rôle.');
        }
        $this->assertUnique(null, $d['email'], $id);
        try {
            $this->pdo->beginTransaction();
            if ($row['role_code'] === 'admin' && $d['role'] !== 'admin') {
                $this->assertNotLastAdmin($id);
            }
            $this->pdo->prepare('UPDATE users SET email = ?, first_name = ?, last_name = ?, timezone = ?, role_id = (SELECT id FROM roles WHERE code = ?) WHERE id = ?')
                ->execute([$d['email'], $d['first_name'], $d['last_name'], $d['timezone'], $d['role'], $id]);
            $this->pdo->commit();
        } catch (PDOException $x) {
            $this->pdo->rollBack();
            throw self::duplicate($x);
        } catch (HttpException $x) {
            $this->pdo->rollBack();
            throw $x;
        }
        $changes = array_keys(array_filter(['email' => $row['email'] !== $d['email'], 'first_name' => $row['first_name'] !== $d['first_name'], 'last_name' => $row['last_name'] !== $d['last_name'],
            'timezone' => $row['timezone'] !== $d['timezone'], 'role' => $row['role_code'] !== $d['role']]));
        $this->audit->log('user.update', $admin->id, 'user', $id, $ip, ['fields' => $changes] + ($row['role_code'] !== $d['role'] ? ['role_from' => $row['role_code'], 'role_to' => $d['role']] : []));
        if ($row['role_code'] !== $d['role']) {
            $this->revokeSessions($id); // les droits changent : reconnexion
        }
        return $this->get($id);
    }

    /** @return array<string,mixed> */
    public function setActive(AuthUser $admin, int $id, bool $active, string $ip): array
    {
        $row = $this->row($id);
        if (!$active) {
            if ($id === $admin->id) {
                throw new HttpException(409, 'Vous ne pouvez pas désactiver votre propre compte.');
            }
            try {
                $this->pdo->beginTransaction();
                if ($row['role_code'] === 'admin' && (int) $row['is_active'] === 1) {
                    $this->assertNotLastAdmin($id);
                }
                $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
                $this->pdo->commit();
            } catch (HttpException $x) {
                $this->pdo->rollBack();
                throw $x;
            }
            $this->revokeSessions($id);
        } else {
            $this->pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$id]);
        }
        $this->audit->log($active ? 'user.activate' : 'user.deactivate', $admin->id, 'user', $id, $ip);
        return $this->get($id);
    }

    /** @return array{user:array<string,mixed>,temporary_password:string} */
    public function resetPassword(AuthUser $admin, int $id, ?string $password, string $ip): array
    {
        $row = $this->row($id);
        $password = $password === null || $password === '' ? self::generatePassword() : $password;
        if ($errs = PasswordHasher::validateStrength($password, 12, $row['username'])) {
            throw new HttpException(422, 'Mot de passe trop faible.', ['password' => $errs]);
        }
        $this->users->setPassword($id, PasswordHasher::hash($password), true);
        $this->revokeSessions($id);
        $this->clearThrottle($row);
        $this->audit->log('user.password_reset', $admin->id, 'user', $id, $ip);
        return ['user' => $this->get($id), 'temporary_password' => $password];
    }

    /** Efface les échecs de connexion récents de ce compte (identifiant et e-mail). Le plafond par adresse IP n'est pas concerné. @return array<string,mixed> */
    public function unlock(AuthUser $admin, int $id, string $ip): array
    {
        $row = $this->row($id);
        $this->clearThrottle($row);
        $this->audit->log('user.unlock', $admin->id, 'user', $id, $ip);
        return $this->get($id);
    }

    /** @return array{revoked:int} */
    public function revokeAll(AuthUser $admin, int $id, string $ip): array
    {
        $this->row($id);
        $n = $this->revokeSessions($id);
        $this->audit->log('user.sessions_revoked', $admin->id, 'user', $id, $ip, ['count' => $n]);
        return ['revoked' => $n];
    }

    /** Mot de passe provisoire : 16 caractères sans signes ambigus, avec majuscule, minuscule et chiffre garantis. */
    public static function generatePassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $chars = array_map(static fn ($s) => $s[random_int(0, strlen($s) - 1)], $sets);
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $st = $this->pdo->prepare('SELECT u.*, r.code AS role_code, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new HttpException(404, 'Utilisateur introuvable.');
    }

    /** @param array<string,mixed> $r @return array<string,mixed> jamais de hash de mot de passe */
    private function present(array $r): array
    {
        return ['id' => (int) $r['id'], 'username' => $r['username'], 'email' => $r['email'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
            'role' => $r['role_code'], 'role_label' => $r['role_label'], 'is_active' => (bool) $r['is_active'], 'must_change_password' => (bool) $r['must_change_password'],
            'timezone' => $r['timezone'], 'last_login_at' => $r['last_login_at'] ? str_replace(' ', 'T', $r['last_login_at']) . 'Z' : null, 'created_at' => str_replace(' ', 'T', $r['created_at']) . 'Z',
            'locked' => $this->isThrottled($r)];
    }

    /** @param array<string,mixed> $r */
    private function isThrottled(array $r): bool
    {
        $max = (int) \App\Core\Config::get('app.auth.max_failures', 5);
        $since = gmdate('Y-m-d H:i:s', time() - (int) \App\Core\Config::get('app.auth.throttle_window', 900));
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier IN (?, ?) AND succeeded = 0 AND attempted_at >= ?');
        $st->execute([mb_strtolower($r['username']), mb_strtolower($r['email']), $since]);
        return (int) $st->fetchColumn() >= $max;
    }

    /** @param array<string,mixed> $row */
    private function clearThrottle(array $row): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier IN (?, ?) AND succeeded = 0')->execute([mb_strtolower($row['username']), mb_strtolower($row['email'])]);
        $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$row['id']]);
    }

    private function revokeSessions(int $id): int
    {
        $st = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
        $st->execute([gmdate('Y-m-d H:i:s'), $id]);
        return $st->rowCount();
    }

    /** À appeler dans une transaction : verrouille les administrateurs actifs pour que deux requêtes ne les retirent pas ensemble. */
    private function assertNotLastAdmin(int $id): void
    {
        $st = $this->pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1 FOR UPDATE");
        $others = array_filter($st->fetchAll(PDO::FETCH_COLUMN), static fn ($x) => (int) $x !== $id);
        if ($others === []) {
            throw new HttpException(409, 'Il doit rester au moins un administrateur actif.');
        }
    }

    /** @param array<string,mixed> $in @param ?array<string,mixed> $existing @param array<string,list<string>> $e @return array<string,string> */
    private function validate(array $in, ?array $existing, array &$e): array
    {
        $role = $in['role'] ?? null;
        $roles = $this->pdo->query('SELECT code FROM roles')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_string($role) || !in_array($role, $roles, true)) {
            $e['role'][] = 'Rôle invalide (' . implode(', ', $roles) . ').';
        }
        $tz = $in['timezone'] ?? (new \App\Repositories\SettingsRepository($this->pdo))->global()['default_timezone'];
        if (!is_string($tz) || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'][] = 'Fuseau horaire invalide.';
        }
        $first = Rules::text($in, 'first_name', 80, true, $e);
        $last = Rules::text($in, 'last_name', 80, true, $e);
        $email = Rules::email($in, 'email', $e);
        if ($email === null && empty($e['email'])) {
            $e['email'][] = 'Adresse e-mail requise.';
        }
        return ['first_name' => (string) $first, 'last_name' => (string) $last, 'email' => (string) $email, 'role' => (string) $role, 'timezone' => (string) $tz];
    }

    private function assertUnique(?string $username, string $email, ?int $exceptId): void
    {
        $e = [];
        foreach (['username' => $username, 'email' => $email] as $col => $v) {
            if ($v === null) {
                continue;
            }
            $st = $this->pdo->prepare("SELECT 1 FROM users WHERE $col = ? AND id <> ? LIMIT 1");
            $st->execute([$v, $exceptId ?? 0]);
            if ($st->fetchColumn()) {
                $e[$col][] = $col === 'email' ? 'Cette adresse e-mail est déjà utilisée.' : 'Cet identifiant est déjà utilisé.';
            }
        }
        Rules::throwIf($e);
    }

    private static function duplicate(PDOException $x): HttpException
    {
        if (($x->errorInfo[1] ?? 0) === 1062) {
            return new HttpException(422, 'Données invalides.', [str_contains($x->getMessage(), 'email') ? 'email' : 'username' => ['Valeur déjà utilisée.']]);
        }
        throw $x;
    }
}
