<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\AuditLogger;
use App\Services\Mail\SmtpException;
use App\Services\Mail\SmtpMailer;
use PDO;

final class EmailApiController
{
    public function __construct(private Authenticator $guard, private PDO $pdo, private AuditLogger $audit, private ?SmtpMailer $mailer = null)
    {
    }

    /** Envoie un e-mail de test à l'adresse du compte (3 par minute au maximum). Les identifiants SMTP ne sont jamais renvoyés. */
    public function test(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $mailer = $this->mailer ?? SmtpMailer::fromConfig();
        if ($mailer === null) {
            throw new HttpException(503, 'L\'envoi d\'e-mails n\'est pas configuré sur ce serveur.');
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'email.test' AND created_at > ?");
        $st->execute([$u->id, gmdate('Y-m-d H:i:s', time() - 60)]);
        if ((int) $st->fetchColumn() >= 3) {
            throw new HttpException(429, 'Trop de tests : patientez une minute.');
        }
        $this->audit->log('email.test', $u->id, null, null, $req->ip);
        try {
            $mailer->send($u->email, 'Test des notifications par e-mail', "Ceci est un message de test de RendezVous Manager.\nSi vous le lisez, les rappels par e-mail fonctionnent.");
        } catch (SmtpException $e) {
            throw new HttpException(502, 'Envoi impossible : ' . $e->getMessage());
        }
        [$local, $domain] = explode('@', $u->email, 2) + [1 => ''];
        return Response::json(['sent' => true, 'to' => mb_substr($local, 0, 1) . '***@' . $domain]);
    }
}
