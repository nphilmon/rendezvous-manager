<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use PDO;

/** Listes en lecture seule alimentant les formulaires (création/édition : phase 10). */
final class CatalogApiController
{
    public function __construct(private Authenticator $guard, private PDO $pdo)
    {
    }

    public function categories(Request $req): Response
    {
        $this->guard->requirePermission($req, 'appointments.read');
        $rows = $this->pdo->query('SELECT id, name, color FROM categories WHERE deleted_at IS NULL ORDER BY name, id')->fetchAll();
        return Response::json(['data' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']], $rows)]);
    }

    /** Un utilisateur voit ses propres contacts ; calendars.manage_all les voit tous. */
    public function contacts(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.read')['user'];
        $sql = "SELECT id, last_name, first_name, company FROM contacts WHERE deleted_at IS NULL";
        $args = [];
        if (!$u->can('calendars.manage_all')) {
            $sql .= ' AND owner_id = ?';
            $args[] = $u->id;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY last_name, first_name, id LIMIT 500');
        $st->execute($args);
        return Response::json(['data' => array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => trim(($r['first_name'] ?? '') . ' ' . $r['last_name']), 'company' => $r['company'],
        ], $st->fetchAll())]);
    }
}
