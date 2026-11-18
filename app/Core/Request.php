<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly string $ip = '0.0.0.0',
        public readonly array $form = [],
        public readonly bool $https = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim(rawurldecode($uri), '/'),
            $_GET,
            (string) file_get_contents('php://input'),
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $_POST,
            !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        foreach (explode(';', $this->headers['cookie'] ?? '') as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === $name) {
                return rawurldecode($v);
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $d = json_decode($this->body, true);
        if (!is_array($d)) {
            throw new HttpException(400, 'Corps JSON invalide.');
        }
        return $d;
    }
}
