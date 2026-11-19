<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Mode maintenance : tant que le fichier storage/maintenance.lock existe, toute requête web reçoit une réponse 503 (avec Retry-After).
 * Utilisé par scripts/update.sh pendant une mise à jour ; `touch` / `rm` suffisent à l'activer ou le lever.
 */
final class MaintenanceMode
{
    public static function active(string $lockFile): bool
    {
        return is_file($lockFile);
    }

    public static function response(bool $api): Response
    {
        $headers = ['Retry-After' => '120', 'Cache-Control' => 'no-store'];
        if ($api) {
            return Response::json(['error' => ['status' => 503, 'message' => 'Maintenance en cours. Réessayez dans quelques minutes.']], 503, $headers);
        }
        return Response::html("<!doctype html><html lang=\"fr\"><meta charset=\"utf-8\"><title>Maintenance</title><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            . "<body style=\"font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem\"><h1>Maintenance en cours</h1>"
            . "<p>L'application est momentanément indisponible pour une mise à jour. Merci de réessayer dans quelques minutes.</p></body></html>", 503, $headers);
    }
}
