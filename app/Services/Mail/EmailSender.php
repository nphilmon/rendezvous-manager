<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;
use App\Services\Notifications\ChannelSender;
use App\Services\Notifications\SendResult;
use PDO;

/** Canal « e-mail » du moteur de notifications (SMTP sécurisé, indépendant du navigateur). */
final class EmailSender implements ChannelSender
{
    public function __construct(private PDO $pdo, private SmtpMailer $mailer)
    {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function send(array $delivery): SendResult
    {
        $st = $this->pdo->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$delivery['user_id']]);
        $to = $st->fetchColumn();
        if (!$to) {
            return SendResult::fail('Destinataire introuvable ou désactivé.');
        }
        $url = rtrim((string) Config::get('app.url', ''), '/');
        $body = (string) ($delivery['body'] ?? '') . ($url !== '' ? "\n\nCentre de notifications : $url/notifications" : '');
        try {
            $this->mailer->send((string) $to, (string) $delivery['title'], $body);
        } catch (SmtpException $e) {
            return $e->transient ? SendResult::retry($e->getMessage()) : SendResult::fail($e->getMessage());
        }
        return SendResult::ok();
    }
}
