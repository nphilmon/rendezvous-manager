<?php
declare(strict_types=1);

namespace App\Services\Transfer;

use App\Controllers\ContactApiController;
use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\CategoryRepository;
use App\Repositories\ContactRepository;
use App\Services\AppointmentService;
use App\Services\AuditLogger;
use App\Services\ChangeRecorder;
use App\Services\PermissionService;
use App\Services\Recurrence\RRule;
use App\Validation\AppointmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Import CSV / ICS (rendez-vous) et CSV (contacts). Chaque ligne passe par les mêmes validations et contrôles de droits que l'API
 * (AppointmentService::create) ; une ligne en erreur n'empêche pas les autres (pas de tout-ou-rien) ; « dry_run » ne modifie rien.
 * Les doublons (même calendrier, titre, début et fin ; même e-mail pour les contacts) sont ignorés, ce qui rend le ré-import sans effet.
 */
final class ImportService
{
    public const MAX_BYTES = 2000000;
    public const MAX_APPOINTMENTS = 1000;
    public const MAX_CONTACTS = 2000;
    private const MAX_REPORTED = 100;

    private const APPT_COLS = ['title' => ['titre', 'title', 'sujet', 'summary', 'objet'], 'calendar' => ['calendrier', 'calendar'], 'start' => ['debut', 'start', 'date de debut', 'date'],
        'end' => ['fin', 'end', 'date de fin'], 'all_day' => ['journee entiere', 'all day', 'all_day', 'allday'], 'timezone' => ['fuseau', 'timezone', 'fuseau horaire'],
        'status' => ['statut', 'status'], 'priority' => ['priorite', 'priority'], 'category' => ['categorie', 'category'], 'contact' => ['contact'],
        'location' => ['lieu', 'location'], 'address' => ['adresse', 'address'], 'description' => ['description'], 'notes' => ['notes', 'note'], 'rrule' => ['recurrence', 'rrule', 'repetition']];
    private const CONTACT_COLS = ['last_name' => ['nom', 'last_name', 'lastname', 'name'], 'first_name' => ['prenom', 'first_name', 'firstname'], 'company' => ['societe', 'company', 'entreprise'],
        'phone' => ['telephone', 'phone', 'tel'], 'email' => ['e-mail', 'email', 'mail', 'courriel'], 'address' => ['adresse', 'address'], 'notes' => ['notes', 'note']];
    private const STATUS = ['planifie' => 'scheduled', 'scheduled' => 'scheduled', 'confirme' => 'confirmed', 'confirmed' => 'confirmed', 'provisoire' => 'tentative', 'tentative' => 'tentative',
        'termine' => 'completed', 'completed' => 'completed', 'annule' => 'cancelled', 'cancelled' => 'cancelled'];
    private const PRIORITY = ['basse' => 'low', 'low' => 'low', 'normale' => 'normal', 'normal' => 'normal', 'haute' => 'high', 'high' => 'high', 'urgente' => 'urgent', 'urgent' => 'urgent'];

    public function __construct(
        private PDO $pdo,
        private PermissionService $perms,
        private AppointmentService $service,
        private ContactRepository $contacts,
        private CategoryRepository $categories,
        private ChangeRecorder $changes,
        private AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function appointments(AuthUser $u, string $format, string $content, ?int $defaultCalendar, bool $dry, string $ip): array
    {
        $this->checkSize($content);
        $tz = new DateTimeZone($u->timezone);
        $items = match ($format) {
            'csv' => $this->fromCsv($content, $tz),
            'ics' => $this->fromIcs($content, $tz),
            default => throw new HttpException(422, 'Format invalide (csv, ics).'),
        };
        if (count($items) > self::MAX_APPOINTMENTS) {
            throw new HttpException(422, 'Trop de rendez-vous dans le fichier (' . self::MAX_APPOINTMENTS . ' maximum par import).');
        }
        $calendars = [];
        foreach ($this->perms->readableCalendarIds($u) as $id) {
            $calendars[$id] = true;
        }
        $names = [];
        foreach ($this->pdo->query('SELECT id, name FROM calendars WHERE deleted_at IS NULL')->fetchAll() as $c) {
            if (isset($calendars[(int) $c['id']])) {
                $names[mb_strtolower((string) $c['name'])] = (int) $c['id'];
            }
        }
        $cats = [];
        foreach ($this->categories->all([]) as $c) {
            $cats[mb_strtolower($c['name'])] = $c['id'];
        }
        $res = ['dry_run' => $dry, 'format' => $format, 'total' => count($items), 'created' => 0, 'would_create' => 0, 'duplicates' => 0, 'ignored' => 0, 'error_count' => 0, 'errors' => [], 'warnings' => []];
        $seen = [];
        $dup = $this->pdo->prepare('SELECT 1 FROM appointments WHERE calendar_id = ? AND title = ? AND start_utc = ? AND end_utc = ? AND deleted_at IS NULL LIMIT 1');
        foreach ($items as $it) {
            $line = $it['line'];
            foreach ($it['warnings'] ?? [] as $w) {
                $this->note($res['warnings'], ['row' => $line, 'message' => $w]);
            }
            if (!empty($it['ignore'])) {
                $res['ignored']++;
                continue;
            }
            try {
                if (!empty($it['error'])) {
                    throw new HttpException(422, $it['error']);
                }
                $in = $it['input'];
                $cal = $in['calendar_id'] ?? null;
                if (isset($it['calendar_name']) && $it['calendar_name'] !== '') {
                    $cal = $names[mb_strtolower($it['calendar_name'])] ?? throw new HttpException(422, 'Calendrier inconnu ou inaccessible : « ' . $it['calendar_name'] . ' ».');
                } elseif ($cal === null && $defaultCalendar !== null) {
                    $cal = $defaultCalendar;
                }
                if ($cal === null) {
                    throw new HttpException(422, 'Calendrier non précisé : choisissez un calendrier par défaut.');
                }
                $in['calendar_id'] = (int) $cal;
                if (!$this->perms->canAccessCalendar($u, $in['calendar_id'], 'create')) {
                    throw new HttpException(403, 'Vous ne pouvez pas créer de rendez-vous dans ce calendrier.');
                }
                if (($it['category_name'] ?? '') !== '') {
                    $cid = $cats[mb_strtolower($it['category_name'])] ?? null;
                    if ($cid === null) {
                        $this->note($res['warnings'], ['row' => $line, 'message' => 'Catégorie inconnue ignorée : « ' . $it['category_name'] . ' ».']);
                    }
                    $in['category_id'] = $cid;
                }
                $data = AppointmentValidator::validate($in, $u->timezone);
                if (!empty($it['rrule'])) {
                    $in['recurrence'] = RRule::toInput(RRule::parse($it['rrule']), new DateTimeZone($data['all_day'] === 1 ? 'UTC' : $data['timezone']));
                    RRule::fromInput($in['recurrence'], new DateTimeZone($data['all_day'] === 1 ? 'UTC' : $data['timezone']));
                }
                $key = $in['calendar_id'] . '|' . $data['title'] . '|' . $data['start_utc'] . '|' . $data['end_utc'];
                $dup->execute([$in['calendar_id'], $data['title'], $data['start_utc'], $data['end_utc']]);
                if (isset($seen[$key]) || $dup->fetchColumn()) {
                    $res['duplicates']++;
                    continue;
                }
                $seen[$key] = true;
                if ($dry) {
                    $res['would_create']++;
                } else {
                    $this->service->create($u, $in + ['ignore_conflicts' => true], $ip);
                    $res['created']++;
                }
            } catch (HttpException $e) {
                $res['error_count']++;
                $this->note($res['errors'], ['row' => $line, 'message' => self::explain($e)]);
            }
        }
        $this->finish($res, $u, $ip, 'appointments');
        return $res;
    }

    /** @return array<string,mixed> */
    public function contacts(AuthUser $u, string $content, bool $dry, string $ip): array
    {
        $this->checkSize($content);
        $rows = Csv::decode($content);
        if (count($rows) < 2) {
            throw new HttpException(422, 'Fichier vide : une ligne d\'en-tête et au moins un contact sont attendus.');
        }
        $map = self::columns(array_shift($rows), self::CONTACT_COLS);
        if (!isset($map['last_name'])) {
            throw new HttpException(422, 'Colonne « Nom » introuvable dans l\'en-tête.');
        }
        if (count($rows) > self::MAX_CONTACTS) {
            throw new HttpException(422, 'Trop de contacts dans le fichier (' . self::MAX_CONTACTS . ' maximum par import).');
        }
        $res = ['dry_run' => $dry, 'format' => 'csv', 'total' => count($rows), 'created' => 0, 'would_create' => 0, 'duplicates' => 0, 'ignored' => 0, 'error_count' => 0, 'errors' => [], 'warnings' => []];
        $exists = $this->pdo->prepare('SELECT 1 FROM contacts WHERE owner_id = ? AND email = ? AND deleted_at IS NULL LIMIT 1');
        $seen = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $in = [];
            foreach ($map as $field => $idx) {
                $v = CellGuard::in(trim($row[$idx] ?? ''));
                $in[$field] = $v === '' ? null : $v;
            }
            try {
                $d = ContactApiController::validate($in);
                if ($d['email'] !== null) {
                    $k = mb_strtolower($d['email']);
                    $exists->execute([$u->id, $d['email']]);
                    if (isset($seen[$k]) || $exists->fetchColumn()) {
                        $res['duplicates']++;
                        continue;
                    }
                    $seen[$k] = true;
                }
                if ($dry) {
                    $res['would_create']++;
                } else {
                    $id = $this->contacts->insert($u->id, $d);
                    $this->changes->record('contact', $id, 'created', $u->id, $ip);
                    $res['created']++;
                }
            } catch (HttpException $e) {
                $res['error_count']++;
                $this->note($res['errors'], ['row' => $line, 'message' => self::explain($e)]);
            }
        }
        $this->finish($res, $u, $ip, 'contacts');
        return $res;
    }

    /** @param array<string,mixed> $res */
    private function finish(array &$res, AuthUser $u, string $ip, string $what): void
    {
        if (!$res['dry_run']) {
            $this->audit->log('data.import', $u->id, null, null, $ip, ['what' => $what, 'format' => $res['format'], 'created' => $res['created'], 'duplicates' => $res['duplicates'], 'errors' => $res['error_count']]);
        }
    }

    /** @param list<array<string,mixed>> $list */
    private function note(array &$list, array $entry): void
    {
        if (count($list) < self::MAX_REPORTED) {
            $list[] = $entry;
        }
    }

    private static function explain(HttpException $e): string
    {
        $parts = [];
        foreach ($e->details as $field => $msgs) {
            $parts[] = $field . ' : ' . implode(' ', (array) $msgs);
        }
        return $parts ? implode(' ; ', $parts) : $e->getMessage();
    }

    private function checkSize(string $c): void
    {
        if (strlen($c) > self::MAX_BYTES) {
            throw new HttpException(413, 'Fichier trop volumineux (' . intdiv(self::MAX_BYTES, 1000000) . ' Mo maximum).');
        }
        if (trim($c) === '') {
            throw new HttpException(422, 'Fichier vide.');
        }
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim(CellGuard::in($s)));
        return strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', "\xEF\xBB\xBF" => '']);
    }

    /** @param list<string> $header @param array<string,list<string>> $aliases @return array<string,int> */
    private static function columns(array $header, array $aliases): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $h = self::norm($h);
            foreach ($aliases as $field => $names) {
                if (!isset($map[$field]) && in_array($h, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }
        return $map;
    }

    /** @return list<array<string,mixed>> */
    private function fromCsv(string $content, DateTimeZone $tz): array
    {
        $rows = Csv::decode($content);
        if (count($rows) < 2) {
            throw new HttpException(422, 'Fichier vide : une ligne d\'en-tête et au moins un rendez-vous sont attendus.');
        }
        $map = self::columns(array_shift($rows), self::APPT_COLS);
        foreach (['title' => 'Titre', 'start' => 'Début'] as $f => $label) {
            if (!isset($map[$f])) {
                throw new HttpException(422, "Colonne « $label » introuvable dans l'en-tête.");
            }
        }
        $out = [];
        $warnedContact = false;
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $g = static fn (string $f): string => isset($map[$f]) ? CellGuard::in(trim($row[$map[$f]] ?? '')) : '';
            $it = ['line' => $line, 'warnings' => []];
            if (isset($map['contact']) && $g('contact') !== '' && !$warnedContact) {
                $it['warnings'][] = 'La colonne « Contact » n\'est pas importée (les contacts se rattachent dans l\'application).';
                $warnedContact = true;
            }
            $rowTz = $tz;
            if ($g('timezone') !== '') {
                try {
                    $rowTz = new DateTimeZone($g('timezone'));
                } catch (\Exception) {
                    $out[] = $it + ['error' => 'Fuseau horaire invalide : « ' . $g('timezone') . ' ».'];
                    continue;
                }
            }
            $status = $g('status') === '' ? 'scheduled' : (self::STATUS[self::norm($g('status'))] ?? null);
            $prio = $g('priority') === '' ? 'normal' : (self::PRIORITY[self::norm($g('priority'))] ?? null);
            if ($status === null || $prio === null) {
                $out[] = $it + ['error' => $status === null ? 'Statut inconnu : « ' . $g('status') . ' ».' : 'Priorité inconnue : « ' . $g('priority') . ' ».'];
                continue;
            }
            $flag = self::norm($g('all_day'));
            $explicit = $flag === '' ? null : in_array($flag, ['oui', 'yes', 'true', '1', 'x', 'vrai'], true);
            $s = self::when($g('start'), $rowTz);
            if ($s === null) {
                $out[] = $it + ['error' => 'Début invalide : « ' . $g('start') . ' » (AAAA-MM-JJ HH:MM ou JJ/MM/AAAA HH:MM).'];
                continue;
            }
            $allDay = $explicit ?? !$s['time'];
            $e = $g('end') === '' ? null : self::when($g('end'), $rowTz);
            if ($g('end') !== '' && $e === null) {
                $out[] = $it + ['error' => 'Fin invalide : « ' . $g('end') . ' ».'];
                continue;
            }
            if ($allDay) {
                $start = $s['date'];
                $end = (new DateTimeImmutable($e['date'] ?? $s['date'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d'); // fin inclusive dans le fichier
            } else {
                if (!$s['time']) {
                    $out[] = $it + ['error' => 'Heure de début manquante (journée entière : « oui »).'];
                    continue;
                }
                $start = $s['iso'];
                if ($e === null || !$e['time']) {
                    $end = (new DateTimeImmutable($s['iso']))->modify('+1 hour')->format('c');
                    $it['warnings'][] = 'Fin absente : durée d\'une heure appliquée.';
                } else {
                    $end = $e['iso'];
                }
            }
            $in = ['title' => $g('title'), 'start' => $start, 'end' => $end, 'all_day' => $allDay ? 1 : 0, 'timezone' => $rowTz->getName(), 'status' => $status, 'priority' => $prio,
                'location' => $g('location'), 'address' => $g('address'), 'description' => $g('description'), 'notes' => $g('notes')];
            $rrule = preg_replace('/^RRULE:/i', '', $g('rrule')) ?? '';
            $out[] = $it + ['input' => $in, 'calendar_name' => $g('calendar'), 'category_name' => $g('category'), 'rrule' => $rrule === '' ? null : $rrule];
        }
        return $out;
    }

    /** @return array{date:string,time:bool,iso:string}|null */
    private static function when(string $v, DateTimeZone $tz): ?array
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?\s*(Z|[+-]\d{2}:?\d{2})?$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $h = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
            $mi = (int) ($m[5] ?? 0);
            $sec = (int) ($m[6] ?? 0);
            $off = $m[7] ?? '';
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T](\d{1,2})[:h](\d{2}))?$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
            $h = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;
            $mi = (int) ($m[5] ?? 0);
            $sec = 0;
            $off = '';
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y) || ($h !== null && ($h > 23 || $mi > 59 || $sec > 59))) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if ($h === null) {
            return ['date' => $date, 'time' => false, 'iso' => $date];
        }
        $local = sprintf('%s %02d:%02d:%02d', $date, $h, $mi, $sec);
        if ($off !== '') {
            $off = $off === 'Z' ? 'Z' : (strpos($off, ':') === false ? substr($off, 0, 3) . ':' . substr($off, 3) : $off);
            $iso = (new DateTimeImmutable(str_replace(' ', 'T', $local) . $off))->format('c');
        } else {
            $iso = (new DateTimeImmutable($local, $tz))->format('c');
        }
        return ['date' => $date, 'time' => true, 'iso' => $iso];
    }

    /** @return list<array<string,mixed>> */
    private function fromIcs(string $content, DateTimeZone $tz): array
    {
        if (!str_contains(strtoupper($content), 'BEGIN:VCALENDAR')) {
            throw new HttpException(422, 'Ce fichier n\'est pas un calendrier iCalendar (BEGIN:VCALENDAR introuvable).');
        }
        $events = Ics::parse($content);
        if ($events === []) {
            throw new HttpException(422, 'Aucun événement (VEVENT) dans le fichier.');
        }
        $clean = static fn (string $s): string => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', Ics::unescape($s)) ?? '');
        $out = [];
        foreach ($events as $i => $ev) {
            $it = ['line' => $i + 1, 'warnings' => []];
            if (isset($ev['RECURRENCE-ID'])) {
                $it['ignore'] = true;
                $it['warnings'][] = 'Occurrence modifiée d\'une série (RECURRENCE-ID) ignorée : les exceptions ne sont pas importées.';
                $out[] = $it;
                continue;
            }
            $s = self::icsTime($ev['DTSTART'] ?? null, $tz);
            if ($s === null) {
                $out[] = $it + ['error' => 'DTSTART absent ou invalide.'];
                continue;
            }
            $e = isset($ev['DTEND']) ? self::icsTime($ev['DTEND'], $tz) : null;
            if (isset($ev['DTEND']) && $e === null) {
                $out[] = $it + ['error' => 'DTEND invalide.'];
                continue;
            }
            if ($s['all_day']) {
                $start = $s['date'];
                $end = $e['date'] ?? (new DateTimeImmutable($s['date']))->modify('+1 day')->format('Y-m-d');
            } else {
                $start = $s['iso'];
                if ($e !== null) {
                    $end = $e['iso'];
                } elseif (isset($ev['DURATION']) && ($sec = self::duration($ev['DURATION']['value'])) !== null && $sec > 0) {
                    $end = (new DateTimeImmutable($s['iso']))->modify("+$sec seconds")->format('c');
                } else {
                    $end = (new DateTimeImmutable($s['iso']))->modify('+1 hour')->format('c');
                    $it['warnings'][] = 'Fin absente : durée d\'une heure appliquée.';
                }
            }
            $status = strtolower($ev['X-RVM-STATUS']['value'] ?? '');
            if (!in_array($status, AppointmentValidator::STATUSES, true)) {
                $status = match (strtoupper($ev['STATUS']['value'] ?? '')) {
                    'TENTATIVE' => 'tentative', 'CANCELLED' => 'cancelled', 'CONFIRMED' => 'confirmed', default => 'scheduled'};
            }
            $p = (int) ($ev['PRIORITY']['value'] ?? 0);
            $prio = $p === 1 ? 'urgent' : ($p >= 2 && $p <= 4 ? 'high' : ($p >= 6 && $p <= 9 ? 'low' : 'normal'));
            $rrule = null;
            if (isset($ev['RRULE'])) {
                $rrule = strtoupper(preg_replace('/(UNTIL=\d{8})(?!T)/i', '$1T235959Z', $ev['RRULE']['value']) ?? '');
                if (isset($ev['EXDATE'])) {
                    $it['warnings'][] = 'Dates exclues (EXDATE) non importées : les occurrences concernées apparaîtront dans la série.';
                }
            }
            $cat = isset($ev['CATEGORIES']) ? trim(explode(',', $ev['CATEGORIES']['value'])[0]) : '';
            $in = ['title' => $clean($ev['SUMMARY']['value'] ?? ''), 'start' => $start, 'end' => $end, 'all_day' => $s['all_day'] ? 1 : 0, 'timezone' => $s['tz'], 'status' => $status, 'priority' => $prio,
                'location' => $clean($ev['LOCATION']['value'] ?? ''), 'description' => $clean($ev['DESCRIPTION']['value'] ?? '')];
            $out[] = $it + ['input' => $in, 'category_name' => $clean($cat), 'rrule' => $rrule];
        }
        return $out;
    }

    /** @param array{params:array<string,string>,value:string}|null $p @return array{all_day:bool,date:string,iso:string,tz:string}|null */
    private static function icsTime(?array $p, DateTimeZone $userTz): ?array
    {
        if ($p === null) {
            return null;
        }
        $v = trim($p['value']);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            $d = "$m[1]-$m[2]-$m[3]";
            return ['all_day' => true, 'date' => $d, 'iso' => $d, 'tz' => 'UTC'];
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $tz = $userTz;
        if ($m[7] === '') {
            if (!empty($p['params']['TZID'])) {
                try {
                    $tz = new DateTimeZone($p['params']['TZID']);
                } catch (\Exception) {
                    $tz = $userTz; // nom de fuseau propriétaire (ex. Windows) : repli sur le fuseau de l'utilisateur
                }
            }
            $d = new DateTimeImmutable("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", $tz);
        } else {
            $d = new DateTimeImmutable("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", new DateTimeZone('UTC'));
        }
        return ['all_day' => false, 'date' => $d->format('Y-m-d'), 'iso' => $d->format('c'), 'tz' => $tz->getName()];
    }

    private static function duration(string $v): ?int
    {
        if (!preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($v), $m)) {
            return null;
        }
        return ((int) ($m[1] ?? 0)) * 604800 + ((int) ($m[2] ?? 0)) * 86400 + ((int) ($m[3] ?? 0)) * 3600 + ((int) ($m[4] ?? 0)) * 60 + (int) ($m[5] ?? 0);
    }
}
