<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** Critères d'installabilité : manifeste complet, icônes réelles, balises présentes dans toutes les pages. */
final class PwaTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testManifestMeetsInstallCriteria(): void
    {
        $m = json_decode((string) file_get_contents($this->root . '/public/manifest.webmanifest'), true);
        $this->assertTrue(is_array($m), 'manifeste JSON invalide');
        foreach (['name', 'short_name', 'start_url', 'scope', 'id', 'theme_color', 'background_color'] as $k) {
            $this->assertTrue(!empty($m[$k]), "clé « $k » manquante");
        }
        $this->assertSame('standalone', $m['display']);
        $sizes = [];
        foreach ($m['icons'] as $i) {
            $file = $this->root . '/public' . $i['src'];
            $this->assertTrue(is_file($file), 'icône absente : ' . $i['src']);
            if (($i['type'] ?? '') === 'image/png') {
                $info = getimagesize($file);
                $this->assertSame($i['sizes'], $info[0] . 'x' . $info[1], 'taille déclarée fausse : ' . $i['src']);
                $sizes[$i['sizes'] . ':' . $i['purpose']] = true;
            }
        }
        $this->assertTrue(isset($sizes['192x192:any']) && isset($sizes['512x512:any']), 'icônes 192 et 512 requises');
        $this->assertTrue(isset($sizes['512x512:maskable']), 'icône maskable requise');
        foreach ($m['shortcuts'] ?? [] as $s) {
            $this->assertTrue($s['url'][0] === '/', 'raccourci hors du site');
        }
    }

    public function testEveryLayoutDeclaresInstallTags(): void
    {
        foreach (['layout.php', 'bare.php'] as $v) {
            $html = (string) file_get_contents($this->root . '/app/Views/' . $v);
            foreach (['rel="manifest"', 'rel="apple-touch-icon"', 'name="theme-color"', 'name="viewport"'] as $tag) {
                $this->assertTrue(str_contains($html, $tag), "$v : $tag manquant");
            }
        }
        $this->assertTrue(is_file($this->root . '/public/assets/icons/apple-touch-icon.png'));
    }

    public function testServiceWorkerIsRegisteredByEveryPage(): void
    {
        $js = (string) file_get_contents($this->root . '/public/assets/js/app.js');
        $this->assertTrue(str_contains($js, "register('/service-worker.js'"), 'service worker non enregistré par app.js');
        $this->assertTrue(str_contains((string) file_get_contents($this->root . '/app/Views/layout.php'), '/assets/js/app.js'));
        $this->assertTrue(str_contains((string) file_get_contents($this->root . '/app/Views/bare.php'), '/assets/js/app.js'));
    }
}
