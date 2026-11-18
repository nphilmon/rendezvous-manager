<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Lecture du journal d'audit (écriture : AuditLogger). */
final class AuditRepository
{
    private const LABELS = [
        'appointment.create' => 'Rendez-vous créé', 'appointment.update' => 'Rendez-vous modifié', 'appointment.cancel' => 'Rendez-vous annulé', 'appointment.complete' => 'Rendez-vous terminé',
        'appointment.reschedule' => 'Rendez-vous reporté', 'appointment.delete' => 'Rendez-vous supprimé', 'appointment.restore' => 'Rendez-vous restauré', 'appointment.duplicate' => 'Rendez-vous dupliqué',
        'appointment.split' => 'Série scindée', 'auth.login' => 'Connexion', 'auth.login_failed' => 'Échec de connexion', 'auth.logout' => 'Déconnexion', 'auth.password_changed' => 'Mot de passe changé',
        'auth.password_change_failed' => 'Échec de changement de mot de passe', 'user.create' => 'Compte créé', 'user.update' => 'Compte modifié', 'user.activate' => 'Compte réactivé',
        'user.deactivate' => 'Compte désactivé', 'user.password_reset' => 'Mot de passe réinitialisé', 'user.unlock' => 'Compte débloqué', 'user.sessions_revoked' => 'Sessions fermées',
        'data.export' => 'Export de données', 'data.import' => 'Import de données', 'email.test' => 'E-mail de test', 'push.subscribe' => 'Abonnement push', 'push.unsubscribe' => 'Désabonnement push',
        'push.test' => 'Notification push de test', 'backup.create' => 'Sauvegarde créée', 'backup.delete' => 'Sauvegarde supprimée', 'backup.download' => 'Sauvegarde téléchargée', 'backup.restore' => 'Sauvegarde restaurée',
    ];
    private const SUFFIX = ['_series' => ' (toute la série)', '_occurrence' => ' (une occurrence)', '_single' => ' (une occurrence)', '_following' => ' (cette occurrence et les suivantes)', '_all' => ' (toute la série)'];

    public function __construct(private PDO $pdo)
    {
    }

    public static function label(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }
        foreach (self::SUFFIX as $suffix => $text) {
            if (str_ends_with($action, $suffix) && isset(self::LABELS[substr($action, 0, -strlen($suffix))])) {
                return self::LABELS[substr($action, 0, -strlen($suffix))] . $text;
            }
        }
        return $action;
    }

    /** @return list<string> actions connues, pour le filtre */
    public static function actions(): array
    {
        $a = array_keys(self::LABELS);
        sort($a);
        return $a;
    }

    /**
     * @param array<string,mixed> $f action (exacte ou préfixe « xxx. »), user_id, entity_type, entity_id, from, to (UTC SQL), q (utilisateur ou action)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $w = ['1=1'];
        $args = [];
        if (($f['action'] ?? '') !== '') {
            if (str_ends_with($f['action'], '.')) {
                $w[] = "a.action LIKE ? ESCAPE '\\\\'";
                $args[] = addcslashes($f['action'], '\\%_') . '%';
            } else {
                $w[] = 'a.action = ?';
                $args[] = $f['action'];
            }
        }
        foreach (['user_id' => 'a.user_id', 'entity_type' => 'a.entity_type', 'entity_id' => 'a.entity_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w[] = "$col = ?";
                $args[] = $f[$k];
            }
        }
        if (($f['from'] ?? '') !== '') {
            $w[] = 'a.created_at >= ?';
            $args[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $w[] = 'a.created_at < ?';
            $args[] = $f['to'];
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $f['q'], '\\%_') . '%';
            $w[] = "(u.username LIKE ? ESCAPE '\\\\' OR u.first_name LIKE ? ESCAPE '\\\\' OR u.last_name LIKE ? ESCAPE '\\\\' OR a.action LIKE ? ESCAPE '\\\\' OR a.ip_address LIKE ? ESCAPE '\\\\')";
            array_push($args, $like, $like, $like, $like, $like);
        }
        $where = ' WHERE ' . implode(' AND ', $w);
        $join = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id';
        $st = $this->pdo->prepare('SELECT COUNT(*)' . $join . $where);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT a.*, u.username, u.first_name, u.last_name' . $join . $where . ' ORDER BY a.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @return list<array<string,mixed>> les plus récentes d'abord */
    public function forEntity(string $type, int $id, int $limit = 100): array
    {
        $st = $this->pdo->prepare('SELECT a.*, u.username, u.first_name, u.last_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.entity_type = ? AND a.entity_id = ? ORDER BY a.id DESC LIMIT ' . max(1, $limit));
        $st->execute([$type, $id]);
        return $st->fetchAll();
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function present(array $r, bool $withIp): array
    {
        $out = ['id' => (int) $r['id'], 'at' => str_replace(' ', 'T', $r['created_at']) . 'Z', 'action' => $r['action'], 'label' => self::label($r['action']),
            'user' => $r['user_id'] === null ? null : ['id' => (int) $r['user_id'], 'name' => $r['username'] === null ? 'Compte supprimé' : trim($r['first_name'] . ' ' . $r['last_name']), 'username' => $r['username']],
            'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'], 'details' => $r['details'] === null ? null : json_decode($r['details'], true)];
        if ($withIp) {
            $out['ip'] = $r['ip_address'];
        }
        return $out;
    }
}
