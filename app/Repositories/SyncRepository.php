<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SyncRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function maxId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM sync_changes')->fetchColumn();
    }

    /** Plus ancien curseur encore garanti complet : au-delà, un client doit tout recharger. */
    public function floor(): int
    {
        $st = $this->pdo->prepare("SELECT setting_value FROM settings WHERE user_id = 0 AND setting_key = 'sync_floor'");
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    /**
     * Modifications postérieures au curseur, limitées à ce que l'utilisateur peut voir :
     * rendez-vous et calendriers de ses calendriers lisibles, catégories (globales), contacts qu'il a lui-même modifiés
     * (ou tous s'il voit tout). Aucun contenu n'est renvoyé : uniquement type, identifiant et nature du changement.
     * @param list<int> $calendarIds
     * @return list<array<string,mixed>>
     */
    public function changesSince(int $cursor, array $calendarIds, int $userId, bool $seesAll, int $limit): array
    {
        $or = ["entity_type = 'category'", $seesAll ? "entity_type = 'contact'" : "(entity_type = 'contact' AND changed_by = ?)"];
        $args = [$cursor];
        if (!$seesAll) {
            $args[] = $userId;
        }
        if ($calendarIds) {
            $or[] = 'calendar_id IN (' . implode(',', array_fill(0, count($calendarIds), '?')) . ')';
            array_push($args, ...$calendarIds);
        }
        $st = $this->pdo->prepare('SELECT id, entity_type, entity_id, change_type, calendar_id, changed_by, created_at FROM sync_changes WHERE id > ? AND (' . implode(' OR ', $or) . ') ORDER BY id LIMIT ' . ($limit + 1));
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Supprime les modifications antérieures à $days jours et relève le plancher. @return int lignes supprimées */
    public function prune(int $days): int
    {
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $st = $this->pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sync_changes WHERE created_at < ?');
        $st->execute([$cut]);
        $upTo = (int) $st->fetchColumn();
        if ($upTo === 0) {
            return 0;
        }
        $del = $this->pdo->prepare('DELETE FROM sync_changes WHERE id <= ?');
        $del->execute([$upTo]);
        $this->pdo->prepare("INSERT INTO settings (user_id, setting_key, setting_value) VALUES (0, 'sync_floor', ?) ON DUPLICATE KEY UPDATE setting_value = GREATEST(CAST(setting_value AS UNSIGNED), VALUES(setting_value))")
            ->execute([(string) $upTo]);
        return $del->rowCount();
    }
}
