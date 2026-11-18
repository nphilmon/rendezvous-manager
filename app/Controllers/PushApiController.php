<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\PushSubscriptionRepository;
use App\Services\AuditLogger;
use App\Services\Push\Base64Url;
use App\Services\Push\EcKeys;
use App\Services\Push\EndpointPolicy;
use App\Services\Push\PushHttpClient;
use App\Services\Push\PushSender;
use App\Services\Push\Vapid;
use PDO;

final class PushApiController
{
    public function __construct(
        private Authenticator $guard,
        private PushSubscriptionRepository $subs,
        private PDO $pdo,
        private AuditLogger $audit,
        private EndpointPolicy $policy,
        private ?PushHttpClient $http = null,
        private ?Vapid $vapid = null,
    ) {
    }

    private function vapid(): ?Vapid
    {
        return $this->vapid ?? Vapid::fromConfig();
    }

    /** Clé publique VAPID : aucun secret n'est jamais renvoyé. */
    public function config(Request $req): Response
    {
        $this->guard->require($req);
        $v = $this->vapid();
        return Response::json(['enabled' => $v !== null, 'public_key' => $v?->publicKey()]);
    }

    public function subscribe(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        if ($this->vapid() === null) {
            throw new HttpException(503, 'Les notifications push ne sont pas configurées sur ce serveur.');
        }
        $b = $req->json();
        $e = [];
        $endpoint = is_string($b['endpoint'] ?? null) ? $b['endpoint'] : '';
        $chk = $this->policy->check($endpoint);
        if (!$chk['ok']) {
            $e['endpoint'][] = $chk['error'];
        }
        $keys = is_array($b['keys'] ?? null) ? $b['keys'] : [];
        $p256 = Base64Url::decode((string) ($keys['p256dh'] ?? ''));
        $auth = Base64Url::decode((string) ($keys['auth'] ?? ''));
        if ($p256 === null || strlen($p256) !== 65) {
            $e['keys'][] = 'Clé p256dh invalide (point P-256 de 65 octets).';
        } else {
            try {
                EcKeys::publicFromPoint($p256);
            } catch (\Throwable) {
                $e['keys'][] = 'Clé p256dh hors de la courbe P-256.';
            }
        }
        if ($auth === null || strlen($auth) !== 16) {
            $e['keys'][] = 'Secret d\'authentification invalide (16 octets).';
        }
        $exp = null;
        if (isset($b['expirationTime']) && $b['expirationTime'] !== null) {
            if (!is_int($b['expirationTime']) && !is_float($b['expirationTime'])) {
                $e['expirationTime'][] = 'Date d\'expiration invalide.';
            } else {
                $exp = gmdate('Y-m-d H:i:s', (int) ($b['expirationTime'] / 1000));
            }
        }
        if ($e) {
            throw new HttpException(422, 'Données invalides.', $e);
        }
        if (!$this->subs->exists($u->id, $endpoint) && $this->subs->countFor($u->id) >= (int) Config::get('notifications.push.max_subscriptions', 10)) {
            throw new HttpException(422, 'Trop d\'appareils abonnés (10 maximum) : retirez-en un avant d\'en ajouter.');
        }
        $id = $this->subs->upsert($u->id, $endpoint, Base64Url::encode($p256), Base64Url::encode($auth), $req->header('User-Agent'), $exp);
        $this->audit->log('push.subscribe', $u->id, 'push_subscription', $id, $req->ip, ['host' => $chk['host']]);
        return Response::json(['ok' => true, 'id' => $id], 201);
    }

    public function unsubscribe(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $endpoint = $req->json()['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            throw new HttpException(422, 'Données invalides.', ['endpoint' => ['Point d\'accès requis.']]);
        }
        $removed = $this->subs->deleteByEndpoint($u->id, $endpoint);
        if ($removed) {
            $this->audit->log('push.unsubscribe', $u->id, 'push_subscription', null, $req->ip);
        }
        return Response::json(['ok' => true, 'removed' => $removed]); // idempotent : déjà retiré = succès
    }

    /** Appareils de l'utilisateur (seul l'hôte du service push est affiché, jamais l'URL complète). */
    public function subscriptions(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $iso = static fn (?string $d): ?string => $d === null ? null : str_replace(' ', 'T', $d) . 'Z';
        return Response::json(['data' => array_map(fn ($s) => [
            'id' => (int) $s['id'], 'service' => (string) parse_url($s['endpoint'], PHP_URL_HOST), 'user_agent' => $s['user_agent'], 'failed_count' => (int) $s['failed_count'],
            'created_at' => $iso($s['created_at']), 'last_success_at' => $iso($s['last_success_at']), 'expires_at' => $iso($s['expires_at']),
        ], $this->subs->allFor($u->id))]);
    }

    /** Notification de test immédiate vers les appareils de l'utilisateur (5 par minute au maximum). */
    public function test(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $v = $this->vapid();
        if ($v === null) {
            throw new HttpException(503, 'Les notifications push ne sont pas configurées sur ce serveur.');
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'push.test' AND created_at > ?");
        $st->execute([$u->id, gmdate('Y-m-d H:i:s', time() - 60)]);
        if ((int) $st->fetchColumn() >= 5) {
            throw new HttpException(429, 'Trop de tests : patientez une minute.');
        }
        $this->audit->log('push.test', $u->id, null, null, $req->ip);
        $sender = new PushSender($this->subs, $v, $this->http ?? new \App\Services\Push\CurlPushHttpClient(), $this->policy);
        $r = $sender->deliver($u->id, json_encode(['title' => 'Notification de test', 'body' => 'Les notifications push fonctionnent sur cet appareil.', 'url' => '/settings', 'tag' => 'rvm-test'], JSON_UNESCAPED_UNICODE));
        return Response::json(['sent' => $r['sent'], 'total' => $r['total'], 'expired' => $r['expired'], 'failed' => $r['temporary'] + $r['permanent'], 'errors' => $r['errors']]);
    }

    /** Historique des envois push de l'utilisateur. */
    public function deliveries(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $st = $this->pdo->prepare("SELECT id, title, status, attempts, last_error, scheduled_at, sent_at FROM notification_deliveries WHERE user_id = ? AND channel = 'push' ORDER BY id DESC LIMIT 20");
        $st->execute([$u->id]);
        $iso = static fn (?string $d): ?string => $d === null ? null : str_replace(' ', 'T', $d) . 'Z';
        return Response::json(['data' => array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'attempts' => (int) $r['attempts'],
            'error' => $r['last_error'], 'scheduled_at' => $iso($r['scheduled_at']), 'sent_at' => $iso($r['sent_at'])], $st->fetchAll())]);
    }
}
