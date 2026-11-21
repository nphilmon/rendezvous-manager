<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\Geo\AddressException;
use App\Services\Geo\AddressSearch;

/** Suggestions d'adresses pour le formulaire de rendez-vous (relais serveur vers l'API Adresse). */
final class GeoApiController
{
    private const PER_MINUTE = 60;

    public function __construct(private Authenticator $guard, private AddressSearch $search, private ?string $throttleDir = null)
    {
    }

    /** GET /api/v1/geo/address?q= (3 à 200 caractères) → { data: [{label, name, postcode, city}], meta: {enabled} } ; 60 appels par minute et par compte. */
    public function address(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.write')['user'];
        if (!$this->search->enabled()) {
            return Response::json(['data' => [], 'meta' => ['enabled' => false]]);
        }
        $q = $req->query['q'] ?? '';
        if (!is_string($q) || mb_strlen(trim($q)) < 3 || mb_strlen($q) > 200 || preg_match('/[\x00-\x1F\x7F]/', $q) === 1) {
            throw new HttpException(422, 'Saisissez entre 3 et 200 caractères.');
        }
        $this->throttle($u->id);
        try {
            return Response::json(['data' => $this->search->search(trim($q)), 'meta' => ['enabled' => true]]);
        } catch (AddressException $e) {
            throw new HttpException(503, $e->getMessage());
        }
    }

    /** Compteur par minute dans un fichier (une ligne « minute:nombre »), sans base de données ni donnée personnelle. */
    private function throttle(int $userId): void
    {
        $dir = $this->throttleDir ?? dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            return; // pas de limitation possible : le service public applique la sienne
        }
        $f = $dir . '/geo_throttle_' . $userId . '.cnt';
        $h = @fopen($f, 'c+');
        if ($h === false) {
            return;
        }
        flock($h, LOCK_EX);
        [$min, $n] = array_pad(array_map('intval', explode(':', (string) stream_get_contents($h))), 2, 0);
        $now = intdiv(time(), 60);
        $n = $min === $now ? $n + 1 : 1;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, $now . ':' . $n);
        flock($h, LOCK_UN);
        fclose($h);
        if ($n > self::PER_MINUTE) {
            throw new HttpException(429, 'Trop de recherches : patientez une minute.');
        }
    }
}
