<?php
declare(strict_types=1);

namespace App\Core;

final class Application
{
    public static function handle(Request $req, Router $router): Response
    {
        try {
            return $router->dispatch($req);
        } catch (HttpException $e) {
            return Response::json(['error' => ['status' => $e->status, 'message' => $e->getMessage()] + ($e->details ? ['details' => $e->details] : [])], $e->status);
        } catch (\Throwable $e) {
            Logger::log('error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            $msg = Config::get('app.debug') ? $e->getMessage() : 'Erreur interne du serveur.';
            return Response::json(['error' => ['status' => 500, 'message' => $msg]], 500);
        }
    }
}
