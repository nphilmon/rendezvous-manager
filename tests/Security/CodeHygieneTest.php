<?php
declare(strict_types=1);

namespace Tests\Security;

use Tests\TestCase;

/**
 * Contrôles statiques du code source : ils font échouer la suite si une règle de conception du projet (pas d'innerHTML, pas de script ni de
 * style en ligne, pas de vérification TLS désactivée, pas de secret en dur, pas de commande système, schéma à jour…) est enfreinte plus tard.
 */
final class CodeHygieneTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function files(array $dirs, string $ext): array
    {
        $out = [];
        foreach ($dirs as $d) {
            $path = $this->root . '/' . $d;
            if (is_file($path)) {
                $out[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && preg_match('/\.' . $ext . '$/', $f->getFilename())) {
                    $out[] = $f->getPathname();
                }
            }
        }
        sort($out);
        return $out;
    }

    /** @param list<string> $files @return list<string> « fichier:ligne : texte » des lignes qui correspondent */
    private function grep(array $files, string $regex, ?callable $skip = null): array
    {
        $hits = [];
        foreach ($files as $f) {
            foreach (file($f) ?: [] as $i => $line) {
                if (preg_match($regex, $line) && ($skip === null || !$skip($line, $f))) {
                    $hits[] = str_replace($this->root . '/', '', $f) . ':' . ($i + 1) . ' : ' . trim($line);
                }
            }
        }
        return $hits;
    }

    public function testEveryPhpFileIsSyntacticallyValid(): void
    {
        $bad = [];
        foreach ($this->files(['app', 'api', 'config', 'installer', 'scripts', 'workers', 'public', 'tests'], 'php') as $f) {
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $bad[] = $f . ' : ' . implode(' ', $out);
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function testEveryJavaScriptFileParsesAndAvoidsDangerousApis(): void
    {
        $js = $this->files(['public/assets/js', 'public/service-worker.js', 'tests/e2e'], 'js');
        $this->assertTrue(count($js) >= 15, 'fichiers JavaScript trouvés');
        $bad = $this->grep($this->files(['public/assets/js', 'public/service-worker.js'], 'js'),
            '/\.(innerHTML|outerHTML)\b|insertAdjacentHTML|document\.write\(|\beval\(|new Function\(|setTimeout\(\s*[\'"]|setInterval\(\s*[\'"]|\.srcdoc\b/');
        $this->assertSame([], $bad, "API dangereuse dans le JavaScript :\n" . implode("\n", $bad));
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            return;
        }
        $fails = [];
        foreach ($js as $f) {
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($f) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $fails[] = $f;
            }
        }
        $this->assertSame([], $fails, 'erreur de syntaxe JavaScript : ' . implode(', ', $fails));
    }

    public function testViewsHaveNoInlineScriptStyleOrHandlerAndEscapeOutput(): void
    {
        $views = $this->files(['app/Views', 'installer'], 'php');
        $this->assertTrue(count($views) >= 10);
        $inline = $this->grep($views, '/<script(?![^>]*\b(src|nonce)=)|\son[a-z]+\s*=\s*["\']|\sstyle\s*=|javascript:/i');
        $this->assertSame([], $inline, "script (hors src ou nonce de l'assistant), style ou gestionnaire en ligne (CSP) :\n" . implode("\n", $inline));
        // toute sortie <?= doit être échappée ($e(...)), convertie en nombre, ou ne produire que des littéraux (une variable ne sert alors que de condition)
        $raw = [];
        foreach ($views as $f) {
            foreach (file($f) ?: [] as $i => $line) {
                if (preg_match_all('/<\?=(.*?)\?>/', $line, $m)) {
                    foreach ($m[1] as $expr) {
                        if (self::outputsUnescaped($expr)) {
                            $raw[] = str_replace($this->root . '/', '', $f) . ':' . ($i + 1) . ' : <?=' . trim($expr) . '?>';
                        }
                    }
                }
            }
        }
        $this->assertSame([], $raw, "sortie non échappée dans un gabarit :\n" . implode("\n", $raw));
    }

    /** Vrai si l'expression de sortie peut écrire une variable sans l'échapper ni la convertir en nombre. */
    private static function outputsUnescaped(string $expr): bool
    {
        $x = trim($expr);
        if (str_starts_with($x, 'match')) {
            return false; // bras littéraux, relu à la main (installer/views/verify.php)
        }
        $x = preg_replace('/\$e\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $x) ?? $x;          // $e(...)
        $x = preg_replace('/\((int|bool|float)\)\s*\$[A-Za-z_][\w\[\]\'"]*/', '', $x) ?? $x;                  // (int) $x
        $x = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', "''", $x) ?? $x;           // littéraux
        // ternaire imbriqué entre parenthèses : on ne garde que ses résultats
        $x = preg_replace_callback('/\(([^()]*?)(?<!\?)\?(?![?>])([^()]*)\)/', static fn (array $m) => $m[2], $x) ?? $x;
        if (preg_match('/(?<!\?)\?(?![?>])/', $x, $m, PREG_OFFSET_CAPTURE)) {
            $x = substr($x, $m[0][1] + 1); // ternaire : seule la partie résultat est écrite
        }
        $x = str_replace(['$content', '$n', '$cls'], '', $x); // gabarit déjà échappé / entier de boucle / classe CSS calculée
        return (bool) preg_match('/\$\w/', $x);
    }

    public function testTemplateOutputCheckerItselfWorks(): void
    {
        foreach ([' $x ', ' $a ? $b : "" ', ' $v["a"] ?? "" ', ' $x . $e($y) '] as $bad) {
            $this->assertTrue(self::outputsUnescaped($bad), "doit être signalé : $bad");
        }
        foreach ([' $e($x) ', ' $a ? "x" : "y" ', ' (int) $n ', ' $k === $v ? \'a\' : \'\' ', ' $e($a) . $e($b) '] as $ok) {
            $this->assertTrue(!self::outputsUnescaped($ok), "doit être accepté : $ok");
        }
    }

    public function testServerCodeHasNoDangerousConstructs(): void
    {
        $php = $this->files(['app', 'api', 'config', 'installer', 'workers', 'scripts', 'public'], 'php');
        $rules = [
            'vérification TLS désactivée' => '/CURLOPT_SSL_VERIFY(PEER|HOST)\s*,\s*(false|0)|[\'"]verify_peer(_name)?[\'"]\s*=>\s*false|[\'"]allow_self_signed[\'"]\s*=>\s*true|CURLOPT_PROXY_SSL_VERIFY/',
            'exécution de commande' => '/(?<![>:\w$])(shell_exec|system|passthru|popen|proc_open|pcntl_exec)\s*\(/',
            'exec() système' => '/(?<![>:\w$])exec\s*\(/',
            'désérialisation / eval' => '/(?<![>:\w$])(unserialize|eval|assert|create_function)\s*\(/',
            'sortie de débogage' => '/(?<![>:\w$])(var_dump|print_r|var_export|debug_zval_dump|phpinfo|dd)\s*\(/',
            'include dynamique' => '/\b(include|require)(_once)?\s*\(?\s*\$_(GET|POST|REQUEST|COOKIE)/',
            'superglobale lue hors de Request' => '/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/',
        ];
        $found = [];
        foreach ($rules as $name => $re) {
            $skip = static fn (string $line, string $file) => ($name === 'exec() système' && str_contains($file, 'scripts/')) || ($name === 'superglobale lue hors de Request' && (str_ends_with($file, 'Core/Request.php') || str_ends_with($file, 'public/index.php')));
            foreach ($this->grep($php, $re, $skip) as $hit) {
                $found[] = "$name — $hit";
            }
        }
        foreach ($php as $f) { // opérateur « backtick » (exécution de commande) : détecté par l'analyseur, pas par expression régulière
            foreach (token_get_all((string) file_get_contents($f)) as $t) {
                if ($t === '`') {
                    $found[] = 'opérateur backtick — ' . str_replace($this->root . '/', '', $f);
                }
            }
        }
        $this->assertSame([], $found, "construction dangereuse :\n" . implode("\n", $found));
    }

    public function testNoHardCodedSecretsAndEnvIsNotTracked(): void
    {
        $files = $this->files(['app', 'api', 'config', 'installer', 'workers', 'scripts', 'public'], 'php');
        $hits = $this->grep($files, '/[\'"]?(password|passwd|secret|api_?key|token|private_key)[\'"]?\s*(=>|=)\s*[\'"][^\'"$\s]{8,}[\'"]/i',
            static fn (string $l) => (bool) preg_match('/(Env::|\$_ENV|getenv|placeholder|dummy|hash|label|\'\')/i', $l));
        $this->assertSame([], $hits, "secret probable en dur :\n" . implode("\n", $hits));
        $this->assertTrue(!is_file($this->root . '/.env') || str_contains((string) shell_exec('cd ' . escapeshellarg($this->root) . ' && git check-ignore .env 2>&1'), '.env'), '.env doit être ignoré par git');
        $tracked = trim((string) shell_exec('cd ' . escapeshellarg($this->root) . ' && git ls-files 2>/dev/null | grep -E "(^|/)\.env$|\.sql\.gz$|install\.(lock|token)$|\.pem$|\.key$" || true'));
        $this->assertSame('', $tracked, "fichier sensible suivi par git : $tracked");
        $example = (string) file_get_contents($this->root . '/.env.example');
        foreach (['DB_PASSWORD', 'MAIL_PASSWORD', 'VAPID_PRIVATE_KEY'] as $k) {
            $this->assertTrue((bool) preg_match('/^' . $k . '=\s*$/m', $example), "$k doit être vide dans .env.example");
        }
    }

    public function testOnlyTheFrontControllerIsPubliclyExecutable(): void
    {
        $exec = array_map(fn ($f) => str_replace($this->root . '/public/', '', $f), $this->files(['public'], 'php'));
        $this->assertSame(['index.php'], $exec, 'seul index.php est exécutable dans public/');
        $forbidden = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/public', \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && preg_match('/\.(sql|gz|log|env|bak|zip|md)$|^\.env/', $f->getFilename())) {
                $forbidden[] = $f->getFilename();
            }
        }
        $this->assertSame([], $forbidden, 'fichier sensible dans public/ : ' . implode(', ', $forbidden));
        $this->assertTrue(str_contains((string) file_get_contents($this->root . '/storage/.htaccess'), 'Require all denied'), 'storage/ interdit au web');
    }

    public function testGeneratedSchemaMatchesMigrations(): void
    {
        $expected = "-- RendezVous Manager : schéma complet (généré par scripts/build_schema.php, ne pas modifier à la main)\n"
            . "-- Pour une installation normale, utiliser scripts/migrate.php.\n\n";
        foreach (glob($this->root . '/database/migrations/*.sql') ?: [] as $f) {
            $expected .= '-- ' . basename($f) . "\n" . trim((string) file_get_contents($f)) . "\n\n";
        }
        $this->assertTrue($expected === file_get_contents($this->root . '/database/schema.sql'), 'database/schema.sql périmé : lancer php scripts/build_schema.php');
    }

    public function testEveryMigrationIsNumberedWithoutGaps(): void
    {
        $n = array_map('basename', glob($this->root . '/database/migrations/*.sql') ?: []);
        foreach ($n as $i => $name) {
            $this->assertTrue(str_starts_with($name, sprintf('%04d_', $i + 1)), "migration hors séquence : $name");
        }
    }
}
