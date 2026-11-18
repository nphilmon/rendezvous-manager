<?php
declare(strict_types=1);

/**
 * Faux serveur SMTP pour les tests. Usage : php fake_smtp.php PORT OUTFILE [CERT_PEM [implicit]]
 * Variables : FAKE_SMTP_USER / FAKE_SMTP_PASS (authentification exigée si définies), FAKE_SMTP_RCPT (code de réponse à RCPT, 250 par défaut).
 * Chaque message reçu est ajouté à OUTFILE (une ligne JSON : from, to, data, authed, tls).
 */
[$_, $port, $out] = $argv;
$cert = $argv[3] ?? null;
$implicit = ($argv[4] ?? '') === 'implicit';
$ctx = stream_context_create($cert ? ['ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true]] : []);
$srv = stream_socket_server(($implicit ? 'tls' : 'tcp') . "://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) {
    fwrite(STDERR, "bind: $errstr\n");
    exit(1);
}
$user = getenv('FAKE_SMTP_USER') ?: null;
$pass = getenv('FAKE_SMTP_PASS') ?: null;
$rcptCode = (int) (getenv('FAKE_SMTP_RCPT') ?: 250);
$reply = static function ($c, string $s): void {
    fwrite($c, $s . "\r\n");
};
for (;;) {
    $c = @stream_socket_accept($srv, 3600);
    if ($c === false) {
        continue; // poignée de main TLS ratée (sonde de disponibilité, client refusant le certificat…) : on attend le suivant
    }
    stream_set_timeout($c, 10);
    $st = ['from' => null, 'to' => [], 'tls' => $implicit, 'authed' => $user === null, 'data' => null];
    $reply($c, '220 fake.local ESMTP');
    while (($line = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        $raw = rtrim($line, "\r\n");
        if ($cmd === 'EHLO') {
            $reply($c, '250-fake.local');
            if ($cert && !$st['tls']) {
                $reply($c, '250-STARTTLS');
            }
            $reply($c, '250-AUTH PLAIN LOGIN');
            $reply($c, '250 8BITMIME');
        } elseif ($cmd === 'STAR') {
            $reply($c, '220 Go ahead');
            if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
                break;
            }
            $st['tls'] = true;
        } elseif ($cmd === 'AUTH') {
            if (!$st['tls'] && $cert) {
                $reply($c, '530 Must issue STARTTLS first');
                continue;
            }
            if (str_starts_with(strtoupper($raw), 'AUTH PLAIN ')) {
                $p = explode("\0", (string) base64_decode(substr($raw, 11)));
                $good = ($p[1] ?? '') === $user && ($p[2] ?? '') === $pass;
            } else { // AUTH LOGIN
                $reply($c, '334 VXNlcm5hbWU6');
                $u = base64_decode(rtrim((string) fgets($c)));
                $reply($c, '334 UGFzc3dvcmQ6');
                $p = base64_decode(rtrim((string) fgets($c)));
                $good = $u === $user && $p === $pass;
            }
            $st['authed'] = $good;
            $reply($c, $good ? '235 Authentication successful' : '535 Authentication failed');
        } elseif ($cmd === 'MAIL') {
            $st['from'] = $raw;
            $reply($c, $st['authed'] ? '250 OK' : '530 Authentication required');
        } elseif ($cmd === 'RCPT') {
            $st['to'][] = $raw;
            $reply($c, $rcptCode === 250 ? '250 OK' : "$rcptCode rejected");
        } elseif ($cmd === 'DATA') {
            $reply($c, '354 End data with <CR><LF>.<CR><LF>');
            $d = '';
            while (($l = fgets($c)) !== false) {
                if ($l === ".\r\n") {
                    break;
                }
                $d .= $l;
            }
            $st['data'] = $d;
            file_put_contents($out, json_encode($st, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            $reply($c, '250 Queued');
        } elseif ($cmd === 'QUIT') {
            $reply($c, '221 Bye');
            break;
        } else {
            $reply($c, '500 Unknown');
        }
    }
    @fclose($c);
}
