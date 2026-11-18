<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ContactRepository
{
    private const SORTS = ['name' => 'c.last_name, c.first_name', 'company' => 'c.company', 'created' => 'c.created_at', 'updated' => 'c.updated_at'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM contacts WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * @param int|null $ownerId null = tous les contacts (droit calendars.manage_all)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(?int $ownerId, string $q, string $sort, string $dir, int $page, int $perPage): array
    {
        $w = ['c.deleted_at IS NULL'];
        $args = [];
        if ($ownerId !== null) {
            $w[] = 'c.owner_id = ?';
            $args[] = $ownerId;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $w[] = "(c.last_name LIKE ? ESCAPE '\\\\' OR c.first_name LIKE ? ESCAPE '\\\\' OR c.company LIKE ? ESCAPE '\\\\' OR c.email LIKE ? ESCAPE '\\\\' OR c.phone LIKE ? ESCAPE '\\\\'
                OR CONCAT(IFNULL(c.first_name, ''), ' ', c.last_name) LIKE ? ESCAPE '\\\\')";
            array_push($args, $like, $like, $like, $like, $like, $like);
        }
        $where = ' WHERE ' . implode(' AND ', $w);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM contacts c' . $where);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $d = $dir === 'desc' ? 'DESC' : 'ASC';
        $order = implode(', ', array_map(fn ($c) => trim($c) . ' ' . $d, explode(',', self::SORTS[$sort] ?? self::SORTS['name']))) . ', c.id ' . $d;
        $st = $this->pdo->prepare('SELECT c.* FROM contacts c' . $where . " ORDER BY $order LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @param array<string,mixed> $d */
    public function insert(int $ownerId, array $d): int
    {
        $this->pdo->prepare('INSERT INTO contacts (owner_id, last_name, first_name, company, phone, email, address, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ownerId, $d['last_name'], $d['first_name'], $d['company'], $d['phone'], $d['email'], $d['address'], $d['notes'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $d */
    public function update(int $id, array $d): void
    {
        $this->pdo->prepare('UPDATE contacts SET last_name = ?, first_name = ?, company = ?, phone = ?, email = ?, address = ?, notes = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')
            ->execute([$d['last_name'], $d['first_name'], $d['company'], $d['phone'], $d['email'], $d['address'], $d['notes'], gmdate('Y-m-d H:i:s'), $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE contacts SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function toApi(array $r, int $userId): array
    {
        return [
            'id' => (int) $r['id'], 'last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'company' => $r['company'],
            'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'], 'notes' => $r['notes'],
            'name' => trim(($r['first_name'] ?? '') . ' ' . $r['last_name']), 'owner_id' => (int) $r['owner_id'], 'mine' => (int) $r['owner_id'] === $userId,
        ];
    }
}
