<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\AuditLogger;
use App\Services\Transfer\ExportService;
use App\Services\Transfer\ImportService;
use App\Validation\Rules;

/** Import / export : droits « data.export » et « data.import » (en plus des droits propres à chaque donnée). */
final class TransferApiController
{
    public function __construct(private Authenticator $guard, private ExportService $export, private ImportService $import, private AuditLogger $audit)
    {
    }

    /** GET /api/v1/export/appointments?format=csv|xlsx|pdf|ics&from=&to=&calendars=&status= */
    public function exportAppointments(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'data.export')['user'];
        $this->guard->requirePermission($req, 'appointments.read');
        $r = $this->export->appointments($u, $req->query);
        $this->audit->log('data.export', $u->id, null, null, $req->ip, ['what' => 'appointments', 'format' => $r['ext'], 'count' => $r['count']]);
        return self::download($r, 'rendezvous');
    }

    /** GET /api/v1/export/contacts?format=csv|xlsx */
    public function exportContacts(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'data.export')['user'];
        $this->guard->requirePermission($req, 'contacts.read');
        $format = $req->query['format'] ?? 'csv';
        $r = $this->export->contacts($u, is_string($format) ? $format : '');
        $this->audit->log('data.export', $u->id, null, null, $req->ip, ['what' => 'contacts', 'format' => $r['ext'], 'count' => $r['count']]);
        return self::download($r, 'contacts');
    }

    /** POST /api/v1/import/appointments {format: csv|ics, content, calendar_id?, dry_run?} */
    public function importAppointments(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'data.import')['user'];
        $in = $req->json();
        [$content, $dry] = self::common($in);
        $format = $in['format'] ?? '';
        $cal = ($in['calendar_id'] ?? null) === null || $in['calendar_id'] === '' ? null : (Rules::id($in['calendar_id']) ?? throw new HttpException(422, 'Calendrier invalide.'));
        return Response::json(['data' => $this->import->appointments($u, is_string($format) ? $format : '', $content, $cal, $dry, $req->ip)]);
    }

    /** POST /api/v1/import/contacts {content, dry_run?} */
    public function importContacts(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'data.import')['user'];
        $this->guard->requirePermission($req, 'contacts.write');
        [$content, $dry] = self::common($req->json());
        return Response::json(['data' => $this->import->contacts($u, $content, $dry, $req->ip)]);
    }

    /** @param array<string,mixed> $in @return array{0:string,1:bool} */
    private static function common(array $in): array
    {
        if (!isset($in['content']) || !is_string($in['content'])) {
            throw new HttpException(422, 'Contenu du fichier manquant.');
        }
        if (isset($in['dry_run']) && !is_bool($in['dry_run'])) {
            throw new HttpException(422, '« dry_run » doit être un booléen.');
        }
        return [$in['content'], (bool) ($in['dry_run'] ?? false)];
    }

    /** @param array{body:string,type:string,ext:string,count:int} $r */
    private static function download(array $r, string $base): Response
    {
        return new Response(200, $r['body'], [
            'Content-Type' => $r['type'],
            'Content-Disposition' => 'attachment; filename="' . $base . '-' . gmdate('Ymd-His') . '.' . $r['ext'] . '"',
            'Cache-Control' => 'no-store',
            'X-Export-Count' => (string) $r['count'],
        ]);
    }
}
