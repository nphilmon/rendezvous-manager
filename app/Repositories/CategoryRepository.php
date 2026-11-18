<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int> $calendarIds calendriers lisibles (pour le décompte)
     * @return list<array<string,mixed>>
     */
    public function all(array $calendarIds): array
    {
        $rows = $this->pdo->query('SELECT id, name, color FROM categories WHERE deleted_at IS NULL ORDER BY name, id')->fetchAll();
        $counts = [];
        if ($calendarIds) {
            $in = implode(',', array_fill(0, count($calendarIds), '?'));
            $st = $this->pdo->prepare("SELECT category_id, COUNT(*) FROM appointments WHERE deleted_at IS NULL AND category_id IS NOT NULL AND calendar_id IN ($in) GROUP BY category_id");
            $st->execute($calendarIds);
            $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'appointment_count' => (int) ($counts[$r['id']] ?? 0)], $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function nameTaken(string $name, ?int $exceptId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM categories WHERE deleted_at IS NULL AND LOWER(name) = LOWER(?) AND (? IS NULL OR id <> ?) LIMIT 1');
        $st->execute([$name, $exceptId, $exceptId]);
        return (bool) $st->fetchColumn();
    }

    public function insert(string $name, string $color, int $userId): int
    {
        $this->pdo->prepare('INSERT INTO categories (name, color, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $color, $userId, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $color): void
    {
        $this->pdo->prepare('UPDATE categories SET name = ?, color = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')->execute([$name, $color, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Supprime logiquement et détache la catégorie des rendez-vous. @return int nombre de rendez-vous détachés */
    public function softDelete(int $id): int
    {
        $st = $this->pdo->prepare('UPDATE appointments SET category_id = NULL WHERE category_id = ?');
        $st->execute([$id]);
        $n = $st->rowCount();
        $this->pdo->prepare('UPDATE categories SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
        return $n;
    }
}
