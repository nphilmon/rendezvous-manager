<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PushSubscriptionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Un même point d'accès appartient à un seul compte : il est réattribué si un autre utilisateur s'abonne depuis ce navigateur. */
    public function upsert(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent, ?string $expiresAt): int
    {
        $this->pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_key, user_agent, expires_at, failed_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth_key = VALUES(auth_key),
                                     user_agent = VALUES(user_agent), expires_at = VALUES(expires_at), failed_count = 0'
        )->execute([$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, $userAgent === null ? null : mb_substr($userAgent, 0, 255), $expiresAt, gmdate('Y-m-d H:i:s')]);
        $st = $this->pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?');
        $st->execute([hash('sha256', $endpoint)]);
        return (int) $st->fetchColumn();
    }

    public function countFor(int $userId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    public function exists(int $userId, string $endpoint): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
        $st->execute([$userId, hash('sha256', $endpoint)]);
        return (bool) $st->fetchColumn();
    }

    /** Abonnements utilisables pour l'envoi (non expirés, peu d'échecs). @return list<array<string,mixed>> */
    public function usableFor(int $userId, int $maxFailures): array
    {
        $st = $this->pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id = ? AND failed_count < ? AND (expires_at IS NULL OR expires_at > ?) ORDER BY id');
        $st->execute([$userId, $maxFailures, gmdate('Y-m-d H:i:s')]);
        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function allFor(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT id, endpoint, user_agent, expires_at, failed_count, last_success_at, created_at FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public function deleteByEndpoint(int $userId, string $endpoint): bool
    {
        $st = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
        $st->execute([$userId, hash('sha256', $endpoint)]);
        return $st->rowCount() > 0;
    }

    public function deleteById(int $id): void
    {
        $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$id]);
    }

    public function markSuccess(int $id): void
    {
        $this->pdo->prepare('UPDATE push_subscriptions SET failed_count = 0, last_success_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Échec permanent (jeton refusé…) : l'abonnement est supprimé après $max échecs consécutifs. */
    public function markFailure(int $id, int $max): void
    {
        $this->pdo->prepare('UPDATE push_subscriptions SET failed_count = failed_count + 1 WHERE id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id = ? AND failed_count >= ?')->execute([$id, $max]);
    }

    public function purgeExpired(): int
    {
        $st = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE expires_at IS NOT NULL AND expires_at < ?');
        $st->execute([gmdate('Y-m-d H:i:s')]);
        return $st->rowCount();
    }
}
