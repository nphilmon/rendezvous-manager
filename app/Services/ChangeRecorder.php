<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Journal de synchronisation (sync_changes) et d'audit pour les entités hors rendez-vous. */
final class ChangeRecorder
{
    public function __construct(private PDO $pdo, private AuditLogger $audit)
    {
    }

    public function record(string $entity, int $id, string $change, int $userId, ?string $ip, ?int $calendarId = null): void
    {
        $this->pdo->prepare('INSERT INTO sync_changes (calendar_id, entity_type, entity_id, change_type, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$calendarId, $entity, $id, $change, $userId, gmdate('Y-m-d H:i:s')]);
        $this->audit->log($entity . '.' . $change, $userId, $entity, $id, $ip);
    }
}
