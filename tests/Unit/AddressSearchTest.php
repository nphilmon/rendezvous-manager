<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Geo\AddressException;
use App\Services\Geo\AddressSearch;
use Tests\TestCase;

/** Recherche d'adresses : aucune requête réseau réelle (client HTTP simulé). */
final class AddressSearchTest extends TestCase
{
    private function ok(string $body, int $status = 200, ?array &$seen = null): \Closure
    {
        return function (string $url) use ($body, $status, &$seen): array {
            $seen[] = $url;
            return ['status' => $status, 'body' => $body];
        };
    }

    public function testParsesAndCleansFeatures(): void
    {
        $body = json_encode(['features' => [
            ['properties' => ['label' => "8 Boulevard du Port 80000 Amiens\x07", 'name' => '8 Boulevard du Port', 'postcode' => '80000', 'city' => 'Amiens']],
            ['properties' => ['label' => '']],
            ['properties' => ['nolabel' => 1]],
            'texte',
        ]]);
        $seen = [];
        $r = (new AddressSearch(true, AddressSearch::DEFAULT_URL, $this->ok($body, 200, $seen)))->search('8 bd du port');
        $this->assertSame([['label' => '8 Boulevard du Port 80000 Amiens', 'name' => '8 Boulevard du Port', 'postcode' => '80000', 'city' => 'Amiens']], $r);
        $this->assertTrue(str_starts_with($seen[0], 'https://api-adresse.data.gouv.fr/search/?q=8%2Bbd%2Bdu%2Bport') || str_contains($seen[0], 'q=8+bd+du+port'), $seen[0]);
        $this->assertTrue(str_contains($seen[0], 'limit=5') && str_contains($seen[0], 'autocomplete=1'));
    }

    public function testAtMostFiveResults(): void
    {
        $f = array_fill(0, 9, ['properties' => ['label' => 'x']]);
        $this->assertSame(5, count((new AddressSearch(true, AddressSearch::DEFAULT_URL, $this->ok(json_encode(['features' => $f]))))->search('abc')));
    }

    public function testFailuresBecomeAddressException(): void
    {
        foreach ([[500, '{}'], [0, ''], [429, '{}'], [200, 'pas du json'], [200, '{"x":1}']] as [$status, $body]) {
            $threw = false;
            try {
                (new AddressSearch(true, AddressSearch::DEFAULT_URL, $this->ok($body, $status)))->search('abc');
            } catch (AddressException) {
                $threw = true;
            }
            $this->assertTrue($threw, "statut $status : exception attendue");
        }
    }

    public function testDisabledOrUnsafeUrlNeverCallsOut(): void
    {
        $called = false;
        $spy = function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '{"features":[]}'];
        };
        foreach ([new AddressSearch(false, AddressSearch::DEFAULT_URL, $spy), new AddressSearch(true, 'http://evil.example/search/', $spy),
                  new AddressSearch(true, 'https://user:pw@api.example/', $spy), new AddressSearch(true, 'ftp://x/', $spy)] as $s) {
            $this->assertTrue(!$s->enabled());
            try {
                $s->search('abc');
            } catch (AddressException) {
            }
        }
        $this->assertTrue(!$called, 'aucun appel sortant quand le service est désactivé ou l\'URL refusée');
        $this->assertTrue(AddressSearch::urlAllowed('http://localhost:9/x') && AddressSearch::urlAllowed('https://api-adresse.data.gouv.fr/search/'));
    }
}
