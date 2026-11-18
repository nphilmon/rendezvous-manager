<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\DashboardService;

final class DashboardApiController
{
    public function __construct(private Authenticator $guard, private DashboardService $service)
    {
    }

    /** GET /api/v1/dashboard?period=week|month|30d|year */
    public function show(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $p = $req->query['period'] ?? 'month';
        if (!is_string($p) || !isset(DashboardService::PERIODS[$p])) {
            throw new HttpException(422, 'Période invalide (' . implode(', ', array_keys(DashboardService::PERIODS)) . ').');
        }
        return Response::json(['data' => $this->service->build($u, $p)]);
    }
}
