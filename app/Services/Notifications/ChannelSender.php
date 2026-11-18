<?php
declare(strict_types=1);

namespace App\Services\Notifications;

interface ChannelSender
{
    /** Canal pris en charge : inapp, push ou email. */
    public function channel(): string;

    /**
     * Envoie une notification réservée par le worker.
     * @param array<string,mixed> $delivery ligne notification_deliveries (titre et corps à jour)
     */
    public function send(array $delivery): SendResult;
}
