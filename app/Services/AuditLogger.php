<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use PDO;

final class AuditLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $details Les champs sensibles sont masqués avant écriture. */
    public function log(string $action, ?int $userId, ?string $entityType = null, ?int $entityId = null, ?string $ip = null, array $details = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $action, $entityType, $entityId, $ip,
            $details ? json_encode(Logger::redact($details), JSON_UNESCAPED_UNICODE) : null,
            gmdate('Y-m-d H:i:s'),
        ]);
    }
}
