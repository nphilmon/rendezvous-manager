<?php
declare(strict_types=1);

namespace App\Services\Push;

use App\Core\Config;
use App\Repositories\PushSubscriptionRepository;
use App\Services\Notifications\ChannelSender;
use App\Services\Notifications\SendResult;

/**
 * Canal « push » du moteur de notifications. Chaque appareil abonné reçoit la charge utile chiffrée ; un point d'accès expiré
 * (404/410) est supprimé. Le résultat est « ok » dès qu'un appareil a accepté la notification (les autres sont relancés
 * uniquement s'ils ont subi une panne temporaire et qu'aucun appareil n'a pu être servi).
 */
final class PushSender implements ChannelSender
{
    public function __construct(
        private PushSubscriptionRepository $subs,
        private Vapid $vapid,
        private PushHttpClient $http,
        private EndpointPolicy $policy,
    ) {
    }

    public function channel(): string
    {
        return 'push';
    }

    /** @param array<string,mixed> $delivery */
    public function send(array $delivery): SendResult
    {
        $payload = json_encode([
            'title' => (string) $delivery['title'], 'body' => (string) ($delivery['body'] ?? ''), 'url' => '/notifications',
            'tag' => 'rvm-' . ($delivery['id'] ?? 'test'), 'id' => $delivery['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $r = $this->deliver((int) $delivery['user_id'], $payload);
        if ($r['sent'] > 0) {
            return SendResult::ok();
        }
        if ($r['total'] === 0) {
            return SendResult::fail('Aucun appareil abonné aux notifications push.');
        }
        $msg = 'Push non remis : ' . implode(' ; ', array_slice($r['errors'], 0, 3));
        return $r['temporary'] > 0 ? SendResult::retry($msg) : SendResult::fail($msg);
    }

    /**
     * Envoie une charge utile à tous les appareils de l'utilisateur.
     * @return array{total:int,sent:int,expired:int,temporary:int,permanent:int,errors:list<string>}
     */
    public function deliver(int $userId, string $payload): array
    {
        $max = (int) Config::get('notifications.push.max_failures', 5);
        $res = ['total' => 0, 'sent' => 0, 'expired' => 0, 'temporary' => 0, 'permanent' => 0, 'errors' => []];
        foreach ($this->subs->usableFor($userId, $max) as $s) {
            $res['total']++;
            $chk = $this->policy->check($s['endpoint']);
            if (!$chk['ok']) {
                $this->subs->markFailure((int) $s['id'], $max);
                $res['permanent']++;
                $res['errors'][] = $chk['error'];
                continue;
            }
            try {
                $body = WebPushEncryptor::encrypt($payload, (string) Base64Url::decode($s['p256dh']), (string) Base64Url::decode($s['auth_key']));
                $out = $this->http->post($s['endpoint'], [
                    'Authorization' => $this->vapid->authorization($s['endpoint']), 'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream',
                    'TTL' => (string) (int) Config::get('notifications.push.ttl', 3600), 'Urgency' => 'high',
                ], $body, (int) Config::get('notifications.push.timeout', 10), $chk['ip']);
            } catch (\Throwable $e) {
                $this->subs->markFailure((int) $s['id'], $max);
                $res['permanent']++;
                $res['errors'][] = 'chiffrement : ' . $e->getMessage();
                continue;
            }
            $st = $out['status'];
            if ($st >= 200 && $st < 300) {
                $this->subs->markSuccess((int) $s['id']);
                $res['sent']++;
            } elseif ($st === 404 || $st === 410) {
                $this->subs->deleteById((int) $s['id']); // abonnement expiré ou révoqué
                $res['expired']++;
                $res['errors'][] = 'abonnement expiré supprimé';
            } elseif ($st === 0 || $st === 429 || $st >= 500) {
                $res['temporary']++;
                $res['errors'][] = $st === 0 ? 'réseau : ' . ($out['error'] ?? 'échec') : "service push HTTP $st";
            } else {
                $this->subs->markFailure((int) $s['id'], $max);
                $res['permanent']++;
                $res['errors'][] = "refusé par le service push (HTTP $st)";
            }
        }
        return $res;
    }
}
