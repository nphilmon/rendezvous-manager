<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Transfer\CellGuard;
use App\Services\Transfer\Csv;
use App\Services\Transfer\ExportService;
use App\Services\Transfer\Ics;
use Tests\DatabaseTestCase;
use ZipArchive;

final class TransferTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        foreach (['alice' => 'user', 'bob' => 'user', 'ro' => 'readonly'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("UPDATE users SET timezone='Europe/Paris'");
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'Perso'), (1,'Travail'), (2,'Calendrier de Bob')");
    }

    private function call(string $as, string $method, string $path, array $body = [], array $query = []): Response
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        return Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    private function import(string $as, string $what, string $format, string $content, array $extra = []): array
    {
        $r = $this->call($as, 'POST', "/api/v1/import/$what", ['format' => $format, 'content' => $content] + $extra);
        return ['status' => $r->status, 'json' => json_decode($r->body, true) ?? []];
    }

    private function add(int $cal, string $title, string $start, string $end, array $extra = []): int
    {
        $extra += ['status' => 'scheduled', 'all_day' => 0, 'description' => null, 'location' => null];
        $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, status, all_day, description, location) VALUES (UUID(),?,1,?,?,?,?,?,?,?)')
            ->execute([$cal, $title, $start, $end, $extra['status'], $extra['all_day'], $extra['description'], $extra['location']]);
        return (int) $this->pdo->lastInsertId();
    }

    // ---- briques ----------------------------------------------------------

    public function testCellGuardNeutralizesFormulasAndRoundTrips(): void
    {
        foreach (['=1+1', '+33 1 23', '-2+3', '@SUM(A1)', "\tx", "\rx", '=HYPERLINK("http://evil","x")'] as $v) {
            $o = CellGuard::out($v);
            $this->assertSame("'" . $v, $o, "protégé : $v");
            $this->assertSame($v, CellGuard::in($o), "aller-retour : $v");
        }
        $this->assertSame('normal', CellGuard::out('normal'));
        $this->assertSame("'quoted", CellGuard::in("'quoted"), 'une apostrophe ordinaire est conservée');
        $this->assertSame('', CellGuard::out(''));
    }

    public function testCsvEncodeDecodeHandlesQuotesNewlinesAndDelimiters(): void
    {
        $rows = [['A', 'B', 'C'], ['dit "oui"', "deux\nlignes", 'a,b;c'], ['=cmd|calc', '', 'é']];
        $csv = Csv::encode($rows);
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM UTF-8');
        $this->assertTrue(str_contains($csv, "\r\n'=cmd|calc"), 'formule neutralisée dans le CSV');
        $back = Csv::decode($csv);
        $this->assertSame(["'=cmd|calc", '', 'é'], $back[2]);
        $this->assertSame(['dit "oui"', "deux\nlignes", 'a,b;c'], $back[1]);
        $semi = Csv::decode("Titre;Début\nRéunion;2026-01-01\n");
        $this->assertSame([['Titre', 'Début'], ['Réunion', '2026-01-01']], $semi, 'séparateur « ; » détecté');
        $win = Csv::decode("Titre,Lieu\n" . iconv('UTF-8', 'Windows-1252', 'Café') . ",Paris\n");
        $this->assertSame('Café', $win[1][0], 'Windows-1252 accepté');
        $this->assertThrows(fn () => Csv::decode("a,b\n\"x,y\n"), 'guillemet non fermé');
    }

    public function testIcsEscapingFoldingAndNoPropertyInjection(): void
    {
        $this->assertSame('a\;b\\,c\\\\d\\nX', Ics::escape("a;b,c\\d\r\nX"));
        $this->assertSame("a;b,c\\d\nX", Ics::unescape('a\;b\\,c\\\\d\\nX'));
        $folded = Ics::fold('DESCRIPTION:' . str_repeat('é', 100));
        foreach (explode("\r\n", rtrim($folded, "\r\n")) as $l) {
            $this->assertTrue(strlen($l) <= 75, 'ligne repliée ≤ 75 octets');
        }
        $this->assertSame('DESCRIPTION:' . str_repeat('é', 100), str_replace("\r\n ", '', rtrim($folded, "\r\n")));
    }

    // ---- exports ----------------------------------------------------------

    public function testCsvExportIsScopedProtectedAndAudited(): void
    {
        $this->add(1, '=HYPERLINK("http://evil.example","clic")', '2026-11-18 09:00:00', '2026-11-18 10:00:00', ['location' => '@lieu']);
        $this->add(3, 'Secret de Bob', '2026-11-18 09:00:00', '2026-11-18 10:00:00');
        $r = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'csv']);
        $this->assertSame(200, $r->status);
        $this->assertTrue(str_starts_with($r->headers['Content-Type'], 'text/csv') && str_contains($r->headers['Content-Disposition'], 'attachment'));
        $this->assertTrue(!str_contains($r->body, 'Secret de Bob'), 'calendrier d\'autrui non exporté');
        $rows = Csv::decode($r->body);
        $this->assertSame(ExportService::APPOINTMENT_HEADERS, $rows[0]);
        $this->assertSame('\'=HYPERLINK("http://evil.example","clic")', $rows[1][0], 'formule neutralisée');
        $this->assertSame("'@lieu", $rows[1][10]);
        $this->assertSame('2026-11-18 10:00', $rows[1][2], 'heure locale (Europe/Paris)');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='data.export'")->fetchColumn(), 'export journalisé');
    }

    public function testExportPermissionsFiltersAndValidation(): void
    {
        $this->add(1, 'Jan', '2026-01-10 09:00:00', '2026-01-10 10:00:00');
        $this->add(1, 'Nov', '2026-11-10 09:00:00', '2026-11-10 10:00:00', ['status' => 'cancelled']);
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/export/appointments', [], '', [], '203.0.113.1'), $this->router)->status);
        $this->assertSame(403, $this->call('ro', 'GET', '/api/v1/export/appointments', [], ['format' => 'csv'])->status, 'lecture seule sans droit d\'export');
        $ok = fn (array $q) => Csv::decode($this->call('alice', 'GET', '/api/v1/export/appointments', [], $q + ['format' => 'csv'])->body);
        $this->assertSame(3, count($ok([])));
        $this->assertSame('Nov', $ok(['from' => '2026-11-01', 'to' => '2026-11-30'])[1][0]);
        $this->assertSame(2, count($ok(['status' => 'cancelled'])));
        $this->assertSame(1, count($ok(['calendars' => '3'])), 'calendrier d\'autrui : en-tête seul');
        foreach ([['format' => 'docx'], ['from' => 'hier'], ['status' => 'x'], ['calendars' => '1;DROP'], ['from' => '2026-12-01', 'to' => '2026-01-01']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/export/appointments', [], $bad + ['format' => 'csv'])->status, json_encode($bad));
        }
    }

    public function testXlsxExportIsAValidPackageWithoutFormulas(): void
    {
        $this->add(1, '=1+1', '2026-11-18 09:00:00', '2026-11-18 10:00:00', ['description' => '<b>&"x"</b>']);
        $r = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'xlsx']);
        $this->assertSame(200, $r->status);
        $f = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, $r->body);
        $z = new ZipArchive();
        $this->assertTrue($z->open($f) === true, 'archive ZIP valide');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
            $this->assertTrue($z->locateName($part) !== false, "partie $part");
        }
        $sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($f);
        $xml = simplexml_load_string($sheet);
        $this->assertTrue($xml !== false, 'XML bien formé');
        $this->assertTrue(!str_contains($sheet, '<f>') && !str_contains($sheet, '<f '), 'aucune formule');
        $cells = array_map('strval', iterator_to_array($xml->sheetData->row[1]->xpath('*/*/*') ?: [], false));
        $this->assertTrue(in_array("'=1+1", $cells, true) && in_array('<b>&"x"</b>', $cells, true), 'texte neutralisé et échappé : ' . json_encode($cells));
    }

    public function testPdfExportIsWellFormed(): void
    {
        for ($i = 0; $i < 70; $i++) { // plusieurs pages
            $this->add(1, "Rendez-vous n°$i (é) \\ ( )", '2026-11-18 09:00:00', '2026-11-18 10:00:00');
        }
        $r = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'pdf']);
        $pdf = $r->body;
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertTrue(str_starts_with($pdf, '%PDF-1.4') && str_ends_with(rtrim($pdf), '%%EOF'));
        $this->assertTrue((bool) preg_match('/\/Count (\d+)/', $pdf, $m) && (int) $m[1] >= 2, 'plusieurs pages');
        preg_match('/startxref\n(\d+)/', $pdf, $x);
        $this->assertSame('xref', substr($pdf, (int) $x[1], 4), 'startxref pointe sur la table xref');
        preg_match_all('/(\d{10}) 00000 n/', $pdf, $offs);
        foreach ($offs[1] as $i => $o) {
            $this->assertTrue((bool) preg_match('/^' . ($i + 1) . ' 0 obj/', substr($pdf, (int) $o, 12)), 'décalage de l\'objet ' . ($i + 1));
        }
    }

    public function testIcsExportOfSeriesWithExceptionsAndAllDay(): void
    {
        $this->add(1, "Titre;avec\r\nATTENDEE:evil@example.org", '2026-11-18 09:00:00', '2026-11-18 10:00:00', ['description' => "ligne1\nligne2"]);
        $s = $this->add(1, 'Hebdo', '2026-11-16 08:00:00', '2026-11-16 09:00:00');
        $this->pdo->prepare("INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone, occurrence_count) VALUES (?, 'FREQ=WEEKLY;COUNT=4', '2026-11-16 09:00:00', 'Europe/Paris', 4)")->execute([$s]);
        $this->pdo->prepare("INSERT INTO appointment_exceptions (appointment_id, original_start_utc, exception_type) VALUES (?, '2026-11-23 08:00:00', 'cancelled')")->execute([$s]);
        $this->add(1, 'Férié', '2026-12-25 00:00:00', '2026-12-26 00:00:00', ['all_day' => 1]);
        $ics = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'ics'])->body;
        $this->assertTrue(!preg_match('/^ATTENDEE:/m', $ics), 'pas d\'injection de propriété');
        $this->assertTrue(str_contains($ics, 'SUMMARY:Titre\;avec\\nATTENDEE:evil@example.org'));
        $this->assertTrue(str_contains($ics, "RRULE:FREQ=WEEKLY;COUNT=4") && str_contains($ics, 'EXDATE;TZID=Europe/Paris:20251123') === false && str_contains($ics, 'EXDATE;TZID=Europe/Paris:20261123T090000'), 'EXDATE en heure locale');
        $this->assertTrue(str_contains($ics, 'BEGIN:VTIMEZONE') && str_contains($ics, 'TZID:Europe/Paris') && str_contains($ics, 'DTSTART;TZID=Europe/Paris:20261116T090000'));
        $this->assertTrue(str_contains($ics, 'DTSTART;VALUE=DATE:20261225') && str_contains($ics, 'DTEND;VALUE=DATE:20261226'), 'journée entière');
        $this->assertTrue(str_contains($ics, 'DTSTART:20261118T090000Z'), 'rendez-vous simple en UTC');
        $this->assertSame(substr_count($ics, 'BEGIN:VEVENT'), substr_count($ics, 'END:VEVENT'));
        foreach (explode("\r\n", $ics) as $l) {
            $this->assertTrue(strlen($l) <= 75, 'ligne ≤ 75 octets');
        }
    }

    public function testExportRefusesMoreThanTheLimit(): void
    {
        $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc)
            SELECT UUID(), 1, 1, 'x', '2026-01-01 09:00:00', '2026-01-01 10:00:00' FROM (SELECT 1 FROM information_schema.columns LIMIT 100) a, (SELECT 1 FROM information_schema.columns LIMIT 60) b");
        $r = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'csv']);
        $this->assertSame(422, $r->status, 'au-delà de ' . ExportService::MAX_ROWS . ' lignes l\'export est refusé, jamais tronqué');
    }

    // ---- imports ----------------------------------------------------------

    private const CSV = "Titre,Calendrier,Début,Fin,Journée entière,Statut,Priorité,Lieu\nRéunion,Travail,2026-11-18 14:00,2026-11-18 15:30,non,Confirmé,Haute,Salle 2\nFérié,Perso,2026-12-25,2026-12-26,oui,,,\n";

    public function testCsvImportDryRunThenRealThenIdempotent(): void
    {
        $dry = $this->import('alice', 'appointments', 'csv', self::CSV, ['dry_run' => true]);
        $this->assertSame(200, $dry['status'], json_encode($dry['json']));
        $this->assertSame(2, $dry['json']['data']['would_create']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn(), 'dry_run ne crée rien');
        $real = $this->import('alice', 'appointments', 'csv', self::CSV);
        $this->assertSame(2, $real['json']['data']['created'], json_encode($real['json']));
        $a = $this->pdo->query("SELECT * FROM appointments WHERE title='Réunion'")->fetch();
        $this->assertSame('2026-11-18 13:00:00', $a['start_utc'], 'heure locale Paris → UTC');
        $this->assertSame('confirmed', $a['status']);
        $this->assertSame('high', $a['priority']);
        $this->assertSame(2, (int) $a['calendar_id']);
        $f = $this->pdo->query("SELECT * FROM appointments WHERE title='Férié'")->fetch();
        $this->assertSame([1, '2026-12-25 00:00:00', '2026-12-27 00:00:00'], [(int) $f['all_day'], $f['start_utc'], $f['end_utc']], 'fin inclusive du fichier (26) → fin exclusive stockée (27)');
        $again = $this->import('alice', 'appointments', 'csv', self::CSV);
        $this->assertSame([0, 2], [$again['json']['data']['created'], $again['json']['data']['duplicates']], 're-import sans effet');
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='data.import'")->fetchColumn(), 'imports réels journalisés (pas le dry_run)');
    }

    public function testCsvImportReportsRowErrorsWithoutBlockingOthers(): void
    {
        $csv = "Titre,Calendrier,Début,Fin\nBon,Perso,2026-11-18 09:00,2026-11-18 10:00\n,Perso,2026-11-18 09:00,2026-11-18 10:00\nDate,Perso,pas-une-date,\nFin avant,Perso,2026-11-18 10:00,2026-11-18 09:00\nBob,Calendrier de Bob,2026-11-18 09:00,2026-11-18 10:00\nInconnu,Nulle part,2026-11-18 09:00,2026-11-18 10:00\n";
        $r = $this->import('alice', 'appointments', 'csv', $csv)['json']['data'];
        $this->assertSame(1, $r['created']);
        $this->assertSame(5, $r['error_count']);
        $rows = array_column($r['errors'], 'message', 'row');
        $this->assertSame([3, 4, 5, 6, 7], array_keys($rows));
        $this->assertTrue(str_contains($rows[3], 'title'), 'titre vide signalé');
        $this->assertTrue(str_contains($rows[4], 'Début invalide'));
        $this->assertTrue(str_contains($rows[6], 'inconnu ou inaccessible'), 'calendrier d\'autrui : même message qu\'un calendrier inexistant');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn());
    }

    public function testImportNeedsRightsAndAValidBody(): void
    {
        $this->assertSame(403, $this->import('ro', 'appointments', 'csv', self::CSV)['status']);
        $this->assertSame(403, $this->import('ro', 'contacts', 'csv', "Nom\nX\n")['status']);
        $this->assertSame(401, Application::handle(new Request('POST', '/api/v1/import/appointments', [], '{}', [], '203.0.113.1'), $this->router)->status);
        $this->call('alice', 'GET', '/api/v1/export/contacts', [], ['format' => 'csv']);
        $noCsrf = Application::handle(new Request('POST', '/api/v1/import/appointments', [], json_encode(['format' => 'csv', 'content' => self::CSV]), ['cookie' => $this->sessions['alice']['cookie']], '203.0.113.1'), $this->router);
        $this->assertSame(403, $noCsrf->status, 'CSRF exigé');
        foreach ([['format' => 'docx', 'content' => 'x'], ['format' => 'csv'], ['format' => 'csv', 'content' => '   '], ['format' => 'csv', 'content' => 'a,b'], ['format' => 'csv', 'content' => self::CSV, 'dry_run' => 'oui'],
            ['format' => 'ics', 'content' => 'pas un calendrier'], ['format' => 'csv', 'content' => 'Foo,Bar' . "\n" . '1,2']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/import/appointments', $bad)->status, json_encode($bad));
        }
        $big = $this->call('alice', 'POST', '/api/v1/import/appointments', ['format' => 'csv', 'content' => str_repeat('a', 2000001)]);
        $this->assertSame(413, $big->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn());
    }

    public function testIcsImportSeriesAllDayTimezonesAndIgnoredExceptions(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:a@x\r\nDTSTART;TZID=America/New_York:20261118T090000\r\nDTEND;TZID=America/New_York:20261118T100000\r\nSUMMARY:Appel\\, New York\r\nDESCRIPTION:Ligne 1\\nLigne 2\r\nLOCATION:Bureau\r\nSTATUS:TENTATIVE\r\nPRIORITY:1\r\nBEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nDESCRIPTION:alarme\r\nEND:VALARM\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:b@x\r\nDTSTART;VALUE=DATE:20261225\r\nDTEND;VALUE=DATE:20261227\r\nSUMMARY:Vacances\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:c@x\r\nDTSTART:20261116T080000Z\r\nDURATION:PT45M\r\nSUMMARY:Hebdo\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nEXDATE:20261123T080000Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:c@x\r\nRECURRENCE-ID:20261130T080000Z\r\nDTSTART:20261130T090000Z\r\nDTEND:20261130T100000Z\r\nSUMMARY:Hebdo décalé\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:d@x\r\nDTSTART:20261118T080000Z\r\nSUMMARY:Règle inconnue\r\nRRULE:FREQ=HOURLY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $r = $this->import('alice', 'appointments', 'ics', $ics, ['calendar_id' => 1]);
        $d = $r['json']['data'];
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame([3, 1, 1], [$d['created'], $d['ignored'], $d['error_count']], json_encode($d));
        $a = $this->pdo->query("SELECT * FROM appointments WHERE title='Appel, New York'")->fetch();
        $this->assertSame(['2026-11-18 14:00:00', "Ligne 1\nLigne 2", 'tentative', 'urgent', 'America/New_York'], [$a['start_utc'], $a['description'], $a['status'], $a['priority'], $a['timezone']], 'fuseau TZID, texte déséchappé, VALARM ignoré');
        $v = $this->pdo->query("SELECT * FROM appointments WHERE title='Vacances'")->fetch();
        $this->assertSame([1, '2026-12-25 00:00:00', '2026-12-27 00:00:00'], [(int) $v['all_day'], $v['start_utc'], $v['end_utc']]);
        $h = $this->pdo->query("SELECT a.end_utc, r.rrule FROM appointments a JOIN appointment_recurrence r ON r.appointment_id = a.id WHERE a.title='Hebdo'")->fetch();
        $this->assertSame(['2026-11-16 08:45:00', 'FREQ=WEEKLY;COUNT=4'], [$h['end_utc'], $h['rrule']], 'DURATION et série');
        $w = implode(' | ', array_column($d['warnings'], 'message'));
        $this->assertTrue(str_contains($w, 'EXDATE') && str_contains($w, 'RECURRENCE-ID'), 'exceptions non importées : signalé');
        $this->assertTrue(str_contains($d['errors'][0]['message'], 'recurrence'), 'règle hors sous-ensemble refusée : ' . $d['errors'][0]['message']);
    }

    public function testExportThenImportRoundTrip(): void
    {
        $this->add(1, 'Aller-retour', '2026-11-18 09:30:00', '2026-11-18 10:15:00', ['description' => "avec \"guillemets\", virgule\net saut de ligne", 'location' => 'Paris']);
        $this->add(1, 'Journée', '2026-11-20 00:00:00', '2026-11-22 00:00:00', ['all_day' => 1]);
        foreach (['csv'] as $fmt) {
            $body = str_replace(',Perso,', ',Travail,', $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => $fmt])->body); // la colonne « Calendrier » prime
            $r = $this->import('alice', 'appointments', $fmt, $body)['json']['data'];
            $this->assertSame(2, $r['created'], json_encode($r));
        }
        $c = $this->pdo->query("SELECT * FROM appointments WHERE title='Aller-retour' AND calendar_id=2")->fetch();
        $this->assertSame(['2026-11-18 09:30:00', '2026-11-18 10:15:00', "avec \"guillemets\", virgule\net saut de ligne"], [$c['start_utc'], $c['end_utc'], $c['description']]);
        $j = $this->pdo->query("SELECT * FROM appointments WHERE title='Journée' AND calendar_id=2")->fetch();
        $this->assertSame(['2026-11-20 00:00:00', '2026-11-22 00:00:00'], [$j['start_utc'], $j['end_utc']], 'journée entière : mêmes dates après aller-retour');
        // ICS : aller-retour d'un rendez-vous horaire
        $ics = $this->call('alice', 'GET', '/api/v1/export/appointments', [], ['format' => 'ics', 'calendars' => '1'])->body;
        $r = $this->import('alice', 'appointments', 'ics', $ics, ['calendar_id' => 2])['json']['data'];
        $this->assertSame([0, 2], [$r['created'], $r['duplicates']], 'ICS ré-importé dans le calendrier 2 : doublons détectés');
    }

    public function testContactsExportImportAndFormulaStripping(): void
    {
        $csv = "Nom,Prénom,Société,Téléphone,E-mail\nDupont,Marie,ACME,'+33 1 23 45 67 89,marie@example.org\n'=cmd|x,Paul,,,paul@example.org\n,Sans nom,,,\nDoublon,X,,,marie@example.org\nMauvais,Mail,,,pas-un-mail\n";
        $d = $this->import('alice', 'contacts', 'csv', $csv, ['dry_run' => true])['json']['data'];
        $this->assertSame([2, 0], [$d['would_create'], $d['created']], json_encode($d));
        $r = $this->import('alice', 'contacts', 'csv', $csv)['json']['data'];
        $this->assertSame([2, 1, 2], [$r['created'], $r['duplicates'], $r['error_count']], json_encode($r));
        $this->assertSame('+33 1 23 45 67 89', $this->pdo->query("SELECT phone FROM contacts WHERE last_name='Dupont'")->fetchColumn(), 'apostrophe protectrice retirée');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM contacts WHERE last_name='=cmd|x'")->fetchColumn());
        $out = $this->call('alice', 'GET', '/api/v1/export/contacts', [], ['format' => 'csv']);
        $this->assertTrue(str_contains($out->body, "\r\n'=cmd|x,") && str_contains($out->body, "'+33 1 23 45 67 89"), 'contacts : formules neutralisées à l\'export');
        $this->assertSame(0, substr_count($this->call('bob', 'GET', '/api/v1/export/contacts', [], ['format' => 'csv'])->body, 'Dupont'), 'contacts d\'autrui non exportés');
        $this->assertSame(200, $this->call('alice', 'GET', '/api/v1/export/contacts', [], ['format' => 'xlsx'])->status);
        $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/export/contacts', [], ['format' => 'pdf'])->status);
    }

    private function assertThrows(callable $f, string $msg): void
    {
        try {
            $f();
        } catch (\Throwable) {
            return;
        }
        $this->assertTrue(false, "exception attendue : $msg");
    }
}
