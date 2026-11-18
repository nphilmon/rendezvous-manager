<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/** Notification dans l'application : la ligne « sent » du centre de notifications est la notification elle-même. */
final class InAppSender implements ChannelSender
{
    public function channel(): string
    {
        return 'inapp';
    }

    public function send(array $delivery): SendResult
    {
        return SendResult::ok();
    }
}
