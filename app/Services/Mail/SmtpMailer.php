<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;

/**
 * Client SMTP minimal et sécurisé (aucune dépendance) : TLS implicite (ssl, port 465) ou STARTTLS (tls, port 587) avec
 * vérification du certificat ET du nom d'hôte, sans repli en clair ; « none » n'est accepté que vers une machine locale.
 * Les identifiants ne sont envoyés qu'après chiffrement. Les en-têtes sont protégés contre l'injection de lignes.
 */
final class SmtpMailer
{
    /** @param array{host:string,port:int,encryption:string,user:string,password:string,from:string,ca_file?:string,timeout?:int} $cfg */
    public function __construct(private array $cfg)
    {
        if (!in_array($cfg['encryption'], ['tls', 'ssl', 'none'], true)) {
            throw new SmtpException('Chiffrement SMTP invalide (tls, ssl ou none).', false);
        }
        if ($cfg['encryption'] === 'none' && !in_array($cfg['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new SmtpException('SMTP sans chiffrement refusé hors de la machine locale.', false);
        }
        if (!filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) {
            throw new SmtpException('Adresse d\'expédition invalide (MAIL_FROM).', false);
        }
    }

    public static function fromConfig(): ?self
    {
        $c = (array) Config::get('notifications.email', []);
        if (empty($c['enabled']) || ($c['host'] ?? '') === '' || ($c['from'] ?? '') === '') {
            return null;
        }
        try {
            return new self($c);
        } catch (SmtpException) {
            return null;
        }
    }

    /** @throws SmtpException */
    public function send(string $to, string $subject, string $body): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $to)) {
            throw new SmtpException('Adresse du destinataire invalide.', false);
        }
        $enc = $this->cfg['encryption'];
        $timeout = (int) ($this->cfg['timeout'] ?? 15);
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $this->cfg['host'], 'SNI_enabled' => true];
        if (($this->cfg['ca_file'] ?? '') !== '') {
            $ssl['cafile'] = $this->cfg['ca_file'];
        }
        $ctx = stream_context_create(['ssl' => $ssl]);
        $addr = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $this->cfg['host'] . ':' . $this->cfg['port'];
        $sock = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new SmtpException('Connexion SMTP impossible : ' . ($errstr ?: 'erreur ' . $errno), true);
        }
        stream_set_timeout($sock, $timeout);
        try {
            $this->expect($sock, [220]);
            $caps = $this->ehlo($sock);
            if ($enc === 'tls') {
                if (!preg_match('/^STARTTLS$/mi', $caps)) {
                    throw new SmtpException('Le serveur n\'offre pas STARTTLS : envoi refusé (pas de repli en clair).', false);
                }
                $this->command($sock, 'STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($sock, true, $method)) {
                    throw new SmtpException('Échec de la négociation TLS (certificat ou nom d\'hôte non vérifié).', true);
                }
                $caps = $this->ehlo($sock);
            }
            if ($this->cfg['user'] !== '') {
                $this->authenticate($sock, $caps);
            }
            $this->command($sock, 'MAIL FROM:<' . $this->cfg['from'] . '>', [250]);
            $this->command($sock, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($sock, 'DATA', [354]);
            fwrite($sock, $this->message($to, $subject, $body) . "\r\n.\r\n");
            $this->expect($sock, [250]);
            @fwrite($sock, "QUIT\r\n");
        } finally {
            @fclose($sock);
        }
    }

    /** @param resource $s */
    private function ehlo($s): string
    {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
        return $this->command($s, 'EHLO ' . $host, [250]);
    }

    /** @param resource $s */
    private function authenticate($s, string $caps): void
    {
        $plain = preg_match('/^AUTH\b.*\bPLAIN\b/mi', $caps) === 1;
        if ($plain) {
            $this->command($s, 'AUTH PLAIN ' . base64_encode("\0" . $this->cfg['user'] . "\0" . $this->cfg['password']), [235], true);
        } elseif (preg_match('/^AUTH\b.*\bLOGIN\b/mi', $caps)) {
            $this->command($s, 'AUTH LOGIN', [334]);
            $this->command($s, base64_encode($this->cfg['user']), [334], true);
            $this->command($s, base64_encode($this->cfg['password']), [235], true);
        } else {
            throw new SmtpException('Le serveur SMTP n\'offre ni AUTH PLAIN ni AUTH LOGIN.', false);
        }
    }

    /** @param resource $s @param list<int> $ok */
    private function command($s, string $cmd, array $ok, bool $secret = false): string
    {
        if (fwrite($s, $cmd . "\r\n") === false) {
            throw new SmtpException('Connexion SMTP interrompue.', true);
        }
        return $this->expect($s, $ok, $secret ? 'authentification' : null);
    }

    /** @param resource $s @param list<int> $ok */
    private function expect($s, array $ok, ?string $what = null): string
    {
        $text = '';
        do {
            $line = fgets($s, 1024);
            if ($line === false) {
                throw new SmtpException('Réponse SMTP absente (délai dépassé ?).', true);
            }
            $text .= str_replace("\r", '', substr($line, 4));
            $more = isset($line[3]) && $line[3] === '-';
            $code = (int) substr($line, 0, 3);
        } while ($more);
        if (!in_array($code, $ok, true)) {
            $msg = 'SMTP ' . $code . ($what ? ' lors de l\'' . $what : '') . ' : ' . trim(mb_substr($text, 0, 200));
            throw new SmtpException($msg, $code >= 400 && $code < 500);
        }
        return $text;
    }

    private function message(string $to, string $subject, string $body): string
    {
        $clean = static fn (string $v): string => trim(str_replace(["\r", "\n", "\0"], ' ', $v));
        $from = $this->cfg['from'];
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $h = [
            'From: =?UTF-8?B?' . base64_encode('RendezVous Manager') . '?= <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode(mb_substr($clean($subject), 0, 200)) . '?=',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $qp = str_replace("\r\n", "\n", quoted_printable_encode(str_replace("\n", "\r\n", $text)));
        $msg = implode("\r\n", $h) . "\r\n\r\n" . str_replace("\n", "\r\n", $qp);
        return preg_replace('/^\./m', '..', $msg); // transparence du point (RFC 5321 §4.5.2)
    }
}
