<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\AuditLogger;
use App\Services\Backup\BackupService;

/** Sauvegardes (droit « backups.manage »). La restauration n'est volontairement pas exposée sur le web : scripts/restore.php. */
final class BackupApiController
{
    public function __construct(private Authenticator $guard, private BackupService $backups, private AuditLogger $audit, private int $keep)
    {
    }

    public function index(Request $req): Response
    {
        $this->guard->requirePermission($req, 'backups.manage');
        return Response::json(['data' => $this->backups->list(), 'meta' => ['keep' => $this->keep, 'encrypts' => $this->backups->encrypts()]]);
    }

    /** POST /api/v1/backups : crée, vérifie puis applique la rotation. */
    public function create(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'backups.manage')['user'];
        @set_time_limit(300);
        $meta = $this->backups->create($u->id);
        $check = $this->backups->verify($this->backups->path($meta['name']));
        $pruned = $check['ok'] ? $this->backups->prune($this->keep) : [];
        $this->audit->log('backup.create', $u->id, null, null, $req->ip, ['name' => $meta['name'], 'size' => $meta['size'], 'rows' => $meta['rows'], 'pruned' => count($pruned), 'verified' => $check['ok']]);
        return Response::json(['data' => $meta, 'verified' => $check['ok'], 'problems' => $check['problems'], 'pruned' => $pruned], 201);
    }

    public function download(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'backups.manage')['user'];
        $path = $this->backups->path((string) ($p['name'] ?? ''));
        $this->audit->log('backup.download', $u->id, null, null, $req->ip, ['name' => basename($path)]);
        return new Response(200, (string) file_get_contents($path), ['Content-Type' => str_ends_with($path, '.enc') ? 'application/octet-stream' : 'application/gzip', 'Content-Disposition' => 'attachment; filename="' . basename($path) . '"', 'Cache-Control' => 'no-store']);
    }

    public function verify(Request $req, array $p): Response
    {
        $this->guard->requirePermission($req, 'backups.manage');
        $r = $this->backups->verify($this->backups->path((string) ($p['name'] ?? '')));
        return Response::json(['data' => $r]);
    }

    public function destroy(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'backups.manage')['user'];
        $name = (string) ($p['name'] ?? '');
        $this->backups->delete($name);
        $this->audit->log('backup.delete', $u->id, null, null, $req->ip, ['name' => $name]);
        return Response::json(['ok' => true]);
    }
}
