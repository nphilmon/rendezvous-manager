<?php
declare(strict_types=1);

namespace App\Services\Mail;

final class SmtpException extends \RuntimeException
{
    /** @param bool $transient true : réessayer plus tard (réseau, réponse 4xx) ; false : échec définitif (5xx, configuration) */
    public function __construct(string $message, public readonly bool $transient)
    {
        parent::__construct($message);
    }
}
