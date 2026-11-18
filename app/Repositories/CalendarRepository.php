<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CalendarRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int> $ids calendriers déjà autorisés en lecture
     * @return list<array<string,mixed>>
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.name, c.description, c.color, c.kind, c.visibility, c.timezone, c.owner_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS owner_name
             FROM calendars c JOIN users u ON u.id = c.owner_id
             WHERE c.deleted_at IS NULL AND c.id IN ($in) ORDER BY c.name, c.id"
        );
        $st->execute($ids);
        return $st->fetchAll();
    }
}
