<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SyncRepository;
use PDO;

/** Purges périodiques (appelées par workers/cleanup_worker.php via cron). */
final class Maintenance
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,int> lignes supprimées par table */
    public function run(int $syncDays = 30, int $sessionDays = 30, int $attemptDays = 7): array
    {
        $out = ['sync_changes' => (new SyncRepository($this->pdo))->prune($syncDays)];
        $st = $this->pdo->prepare('DELETE FROM user_sessions WHERE (expires_at < ? OR revoked_at IS NOT NULL) AND COALESCE(revoked_at, expires_at) < ?');
        $cut = gmdate('Y-m-d H:i:s', time() - $sessionDays * 86400);
        $st->execute([$cut, $cut]);
        $out['user_sessions'] = $st->rowCount();
        $st = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s', time() - $attemptDays * 86400)]);
        $out['login_attempts'] = $st->rowCount();
        $st = $this->pdo->prepare('DELETE FROM password_resets WHERE expires_at < ? ');
        $st->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
        $out['password_resets'] = $st->rowCount();
        return $out;
    }
}
