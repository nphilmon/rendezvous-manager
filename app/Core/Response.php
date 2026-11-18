<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string,string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $headers + ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    /** @param array<string,string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self($status, '', ['Location' => $to, 'Cache-Control' => 'no-store']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
