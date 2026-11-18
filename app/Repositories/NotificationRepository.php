<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Centre de notifications : lecture, marquage lu/non lu (uniquement les notifications « dans l'application » de l'utilisateur). */
final class NotificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function list(int $userId, string $filter, int $page, int $perPage): array
    {
        $w = "d.user_id = ? AND d.channel = 'inapp' AND d.status = 'sent'";
        if ($filter === 'unread') {
            $w .= ' AND d.read_at IS NULL';
        } elseif ($filter === 'read') {
            $w .= ' AND d.read_at IS NOT NULL';
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notification_deliveries d WHERE $w");
        $st->execute([$userId]);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare("SELECT d.id, d.appointment_id, d.occurrence_start_utc, d.scheduled_at, d.sent_at, d.read_at, d.title, d.body,
                (a.id IS NOT NULL AND a.deleted_at IS NULL) AS appointment_exists
            FROM notification_deliveries d LEFT JOIN appointments a ON a.id = d.appointment_id
            WHERE $w ORDER BY d.sent_at DESC, d.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $st->execute([$userId]);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function unreadCount(int $userId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND channel = 'inapp' AND status = 'sent' AND read_at IS NULL");
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    /** @return bool false si la notification n'existe pas ou n'appartient pas à l'utilisateur */
    public function setRead(int $userId, int $id, bool $read): bool
    {
        $st = $this->pdo->prepare("UPDATE notification_deliveries SET read_at = ? WHERE id = ? AND user_id = ? AND channel = 'inapp' AND status = 'sent'");
        $st->execute([$read ? gmdate('Y-m-d H:i:s') : null, $id, $userId]);
        if ($st->rowCount() === 1) {
            return true;
        }
        $q = $this->pdo->prepare("SELECT 1 FROM notification_deliveries WHERE id = ? AND user_id = ? AND channel = 'inapp' AND status = 'sent'");
        $q->execute([$id, $userId]);
        return (bool) $q->fetchColumn(); // déjà dans l'état demandé
    }

    public function markAllRead(int $userId): int
    {
        $st = $this->pdo->prepare("UPDATE notification_deliveries SET read_at = ? WHERE user_id = ? AND channel = 'inapp' AND status = 'sent' AND read_at IS NULL");
        $st->execute([gmdate('Y-m-d H:i:s'), $userId]);
        return $st->rowCount();
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function toApi(array $r): array
    {
        $iso = static fn (?string $d): ?string => $d === null ? null : str_replace(' ', 'T', $d) . 'Z';
        return ['id' => (int) $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'appointment_id' => $r['appointment_id'] === null ? null : (int) $r['appointment_id'],
            'occurrence_start' => $iso($r['occurrence_start_utc']), 'sent_at' => $iso($r['sent_at']), 'read_at' => $iso($r['read_at']), 'read' => $r['read_at'] !== null,
            'appointment_exists' => (bool) $r['appointment_exists']];
    }
}
