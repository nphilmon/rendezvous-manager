<?php
declare(strict_types=1);

namespace App\Services\Notifications;

final class SendResult
{
    private function __construct(public readonly string $kind, public readonly string $message = '')
    {
    }

    public static function ok(): self
    {
        return new self('ok');
    }

    /** Échec temporaire : nouvelle tentative plus tard (avec repli) tant que le nombre maximal n'est pas atteint. */
    public static function retry(string $why): self
    {
        return new self('retry', $why);
    }

    /** Échec définitif : inutile de réessayer (ex. abonnement push expiré). */
    public static function fail(string $why): self
    {
        return new self('fail', $why);
    }
}
