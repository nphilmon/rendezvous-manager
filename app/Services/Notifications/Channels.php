<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\PushSubscriptionRepository;
use App\Services\Mail\EmailSender;
use App\Services\Mail\SmtpMailer;
use App\Services\Push\CurlPushHttpClient;
use App\Services\Push\EndpointPolicy;
use App\Services\Push\PushSender;
use App\Services\Push\Vapid;
use PDO;

/** Canaux réellement disponibles sur ce serveur (selon la configuration) et leurs expéditeurs. */
final class Channels
{
    /** @return list<string> */
    public static function available(): array
    {
        $c = ['inapp'];
        if (Vapid::fromConfig() !== null) {
            $c[] = 'push';
        }
        if (SmtpMailer::fromConfig() !== null) {
            $c[] = 'email';
        }
        return $c;
    }

    /**
     * @param bool $slowOnly true : push et e-mail seulement (push_worker.php)
     * @return list<ChannelSender>
     */
    public static function senders(PDO $pdo, bool $slowOnly = false): array
    {
        $out = $slowOnly ? [] : [new InAppSender()];
        if (($v = Vapid::fromConfig()) !== null) {
            $out[] = new PushSender(new PushSubscriptionRepository($pdo), $v, new CurlPushHttpClient(), EndpointPolicy::fromConfig());
        }
        if (($m = SmtpMailer::fromConfig()) !== null) {
            $out[] = new EmailSender($pdo, $m);
        }
        return $out;
    }
}
