<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\AuditLogger;
use App\Services\ReportService;
use App\Services\Transfer\Csv;

/** GET /api/v1/reports?from=&to=&format=json|csv (droit « reports.view ») : statistiques d'activité, limitées aux calendriers autorisés. */
final class ReportApiController
{
    public function __construct(private Authenticator $guard, private ReportService $service, private AuditLogger $audit)
    {
    }

    public function show(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'reports.view')['user'];
        $q = $req->query;
        $str = static fn (string $k): ?string => isset($q[$k]) && is_string($q[$k]) ? $q[$k] : null;
        foreach (['from', 'to', 'format'] as $k) {
            if (isset($q[$k]) && !is_string($q[$k])) {
                throw new HttpException(422, "« $k » invalide.");
            }
        }
        $format = $str('format') ?? 'json';
        if (!in_array($format, ['json', 'csv'], true)) {
            throw new HttpException(422, 'Format invalide (json, csv).');
        }
        $report = $this->service->build($u, $str('from'), $str('to'));
        if ($format === 'csv') {
            $this->audit->log('data.export', $u->id, null, null, $req->ip, ['what' => 'report', 'format' => 'csv', 'count' => $report['totals']['appointments']]);
            return new Response(200, Csv::encode(ReportService::toRows($report)), ['Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="rapport-' . $report['period']['from'] . '_' . $report['period']['to'] . '.csv"', 'Cache-Control' => 'no-store']);
        }
        return Response::json(['data' => $report]);
    }
}
