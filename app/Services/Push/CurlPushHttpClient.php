<?php
declare(strict_types=1);

namespace App\Services\Push;

/** Client HTTP (ext-curl) : TLS vérifié, aucune redirection, délai court, adresse IP épinglée. */
final class CurlPushHttpClient implements PushHttpClient
{
    public function post(string $url, array $headers, string $body, int $timeout, ?string $pinIp = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Extension curl absente.'];
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . str_replace(["\r", "\n"], '', $v);
        }
        $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_MAXFILESIZE => 65536];
        $u = parse_url($url);
        if ($pinIp !== null && isset($u['host'])) {
            $port = $u['port'] ?? (($u['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $opts[CURLOPT_RESOLVE] = [$u['host'] . ':' . $port . ':' . $pinIp];
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $err = $res === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $res === false ? 0 : $status, 'body' => is_string($res) ? mb_substr($res, 0, 500) : '', 'error' => $err];
    }
}
