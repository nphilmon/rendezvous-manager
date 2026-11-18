<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Mail\SmtpException;
use App\Services\Mail\SmtpMailer;
use Tests\TestCase;

/** Client SMTP : authentification, TLS vérifié (STARTTLS et implicite), absence de repli en clair, injection d'en-têtes, codes d'erreur. */
final class SmtpMailerTest extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];
    /** @var list<string> */
    private array $files = [];

    private function startServer(array $env = [], ?string $certPem = null, bool $implicit = false): array
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($s, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($s);
        $out = tempnam(sys_get_temp_dir(), 'smtp');
        $this->files[] = $out;
        $args = [PHP_BINARY, dirname(__DIR__) . '/Support/fake_smtp.php', (string) $port, $out];
        if ($certPem) {
            $args[] = $certPem;
            if ($implicit) {
                $args[] = 'implicit';
            }
        }
        $p = proc_open($args, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), $env));
        $this->procs[] = $p;
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port);
            if ($c) {
                fclose($c);
                break;
            }
            usleep(100000);
        }
        return [$port, $out];
    }

    private function stopAll(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p);
            proc_close($p);
        }
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->procs = $this->files = [];
    }

    /** @return array{0:string,1:string} [PEM serveur (certificat + clé), PEM client (certificat seul)] */
    private function certificate(): array
    {
        $cnf = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nx509_extensions = v3\nprompt = no\n[dn]\nCN = localhost\n[v3]\nsubjectAltName = DNS:localhost,IP:127.0.0.1\nbasicConstraints = critical,CA:TRUE\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['config' => $cnf]);
        $x = openssl_csr_sign($csr, null, $key, 2, ['config' => $cnf, 'x509_extensions' => 'v3']);
        openssl_x509_export($x, $cert);
        openssl_pkey_export($key, $pem, null, ['config' => $cnf]);
        $server = tempnam(sys_get_temp_dir(), 'srv');
        $client = tempnam(sys_get_temp_dir(), 'ca');
        file_put_contents($server, $cert . $pem);
        file_put_contents($client, $cert);
        array_push($this->files, $cnf, $server, $client);
        return [$server, $client];
    }

    private function mailer(int $port, string $enc = 'none', array $over = []): SmtpMailer
    {
        return new SmtpMailer($over + ['host' => '127.0.0.1', 'port' => $port, 'encryption' => $enc, 'user' => 'robot', 'password' => 's3cret', 'from' => 'noreply@example.org', 'timeout' => 5]);
    }

    /** @return array<string,mixed> */
    private function received(string $out): array
    {
        $lines = array_filter(array_map('trim', file($out) ?: []));
        return $lines ? json_decode(end($lines), true) : [];
    }

    private function expectFailure(callable $f, bool $transient, string $why): void
    {
        try {
            $f();
            $this->assertTrue(false, "exception attendue : $why");
        } catch (SmtpException $e) {
            $this->assertSame($transient, $e->transient, "$why — {$e->getMessage()}");
        }
    }

    public function testPlainRelayDeliversWellFormedUtf8Message(): void
    {
        try {
            [$port, $out] = $this->startServer(['FAKE_SMTP_USER' => 'robot', 'FAKE_SMTP_PASS' => 's3cret']);
            $this->mailer($port)->send('alice@example.org', "Rappel : réunion €\r\nBcc: evil@example.org", "Ligne 1 é\n.point seul\nLigne 3");
            $m = $this->received($out);
            $this->assertTrue($m['authed'] && !$m['tls']);
            $this->assertTrue(str_contains($m['from'], '<noreply@example.org>') && str_contains($m['to'][0], '<alice@example.org>'));
            [$head, $body] = explode("\r\n\r\n", $m['data'], 2);
            $this->assertTrue(!preg_match('/^Bcc:/mi', $head), 'aucune injection d\'en-tête par le sujet');
            $this->assertSame(1, preg_match('/^Subject: =\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=\r?$/m', $head, $sm));
            $this->assertSame('Rappel : réunion €  Bcc: evil@example.org', base64_decode($sm[1]), 'les sauts de ligne du sujet deviennent des espaces');
            foreach (['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable', 'Auto-Submitted: auto-generated'] as $h) {
                $this->assertTrue(str_contains($head, $h), $h);
            }
            $this->assertTrue(preg_match('/^Message-ID: <[0-9a-f]{24}@example\.org>\r?$/m', $head) === 1 && preg_match('/^Date: .+ \+0000\r?$/m', $head) === 1);
            $decoded = quoted_printable_decode($body);
            $this->assertTrue(str_contains($decoded, "Ligne 1 é") && str_contains($decoded, 'Ligne 3'));
            $this->assertTrue(str_contains($body, "\r\n..point seul"), 'transparence du point : « .point » devient « ..point » sur le fil');
        } finally {
            $this->stopAll();
        }
    }

    public function testAuthenticationAndServerErrors(): void
    {
        try {
            [$port] = $this->startServer(['FAKE_SMTP_USER' => 'robot', 'FAKE_SMTP_PASS' => 's3cret']);
            $this->expectFailure(fn () => $this->mailer($port, 'none', ['password' => 'mauvais'])->send('a@example.org', 's', 'b'), false, 'authentification refusée (535)');
            [$port2] = $this->startServer(['FAKE_SMTP_RCPT' => '550']);
            $this->expectFailure(fn () => $this->mailer($port2, 'none', ['user' => ''])->send('a@example.org', 's', 'b'), false, 'destinataire refusé (5xx) : définitif');
            [$port3] = $this->startServer(['FAKE_SMTP_RCPT' => '451']);
            $this->expectFailure(fn () => $this->mailer($port3, 'none', ['user' => ''])->send('a@example.org', 's', 'b'), true, 'indisponibilité temporaire (4xx)');
            $this->expectFailure(fn () => $this->mailer(1, 'none')->send('a@example.org', 's', 'b'), true, 'connexion refusée : temporaire');
            $this->expectFailure(fn () => $this->mailer($port2, 'none', ['user' => ''])->send("a@example.org\r\nRCPT TO:<x@y.z>", 's', 'b'), false, 'injection dans le destinataire');
            $this->expectFailure(fn () => $this->mailer($port2, 'none', ['user' => ''])->send('pas-une-adresse', 's', 'b'), false, 'adresse invalide');
        } finally {
            $this->stopAll();
        }
    }

    public function testConfigurationGuards(): void
    {
        $this->expectFailure(fn () => new SmtpMailer(['host' => 'smtp.example.org', 'port' => 25, 'encryption' => 'none', 'user' => '', 'password' => '', 'from' => 'a@example.org']), false, 'sans chiffrement hors machine locale');
        $this->expectFailure(fn () => new SmtpMailer(['host' => 'smtp.example.org', 'port' => 25, 'encryption' => 'starttls', 'user' => '', 'password' => '', 'from' => 'a@example.org']), false, 'mode inconnu');
        $this->expectFailure(fn () => new SmtpMailer(['host' => 'smtp.example.org', 'port' => 587, 'encryption' => 'tls', 'user' => '', 'password' => '', 'from' => 'pas une adresse']), false, 'expéditeur invalide');
        $this->assertTrue(SmtpMailer::fromConfig() === null, 'e-mail non configuré par défaut');
    }

    public function testStarttlsVerifiesCertificateAndProtectsCredentials(): void
    {
        [$serverPem, $caPem] = $this->certificate();
        try {
            [$port, $out] = $this->startServer(['FAKE_SMTP_USER' => 'robot', 'FAKE_SMTP_PASS' => 's3cret'], $serverPem);
            $this->mailer($port, 'tls', ['ca_file' => $caPem])->send('alice@example.org', 'Sujet', 'Corps');
            $m = $this->received($out);
            $this->assertTrue($m['tls'] && $m['authed'], 'chiffré puis authentifié (le serveur refuse AUTH avant STARTTLS)');
            // certificat inconnu : la négociation échoue, aucun identifiant n'est envoyé
            [$port2, $out2] = $this->startServer(['FAKE_SMTP_USER' => 'robot', 'FAKE_SMTP_PASS' => 's3cret'], $serverPem);
            $this->expectFailure(fn () => $this->mailer($port2, 'tls')->send('alice@example.org', 'Sujet', 'Corps'), true, 'certificat non approuvé');
            $this->assertSame([], $this->received($out2), 'rien n\'a été remis');
        } finally {
            $this->stopAll();
        }
    }

    public function testNoDowngradeWhenServerLacksStarttls(): void
    {
        try {
            [$port, $out] = $this->startServer();
            $this->expectFailure(fn () => $this->mailer($port, 'tls', ['user' => ''])->send('alice@example.org', 'S', 'C'), false, 'STARTTLS absent : pas de repli en clair');
            $this->assertSame([], $this->received($out));
        } finally {
            $this->stopAll();
        }
    }

    public function testImplicitTls(): void
    {
        [$serverPem, $caPem] = $this->certificate();
        try {
            [$port, $out] = $this->startServer(['FAKE_SMTP_USER' => 'robot', 'FAKE_SMTP_PASS' => 's3cret'], $serverPem, true);
            $this->mailer($port, 'ssl', ['ca_file' => $caPem])->send('alice@example.org', 'Sujet', 'Corps');
            $this->assertTrue($this->received($out)['tls']);
            $this->expectFailure(fn () => $this->mailer($port, 'ssl')->send('alice@example.org', 'S', 'C'), true, 'certificat non approuvé en TLS implicite');
        } finally {
            $this->stopAll();
        }
    }
}
