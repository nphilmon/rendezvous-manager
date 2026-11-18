<?php
declare(strict_types=1);

namespace App\Services\Transfer;

use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use App\Repositories\ContactRepository;
use App\Services\PermissionService;
use App\Validation\AppointmentValidator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Exports : uniquement les calendriers lisibles par l'utilisateur ; au-delà de MAX_ROWS lignes l'export est refusé (jamais tronqué en silence). */
final class ExportService
{
    public const MAX_ROWS = 5000;
    public const APPOINTMENT_HEADERS = ['Titre', 'Calendrier', 'Début', 'Fin', 'Journée entière', 'Fuseau', 'Statut', 'Priorité', 'Catégorie', 'Contact', 'Lieu', 'Adresse', 'Description', 'Notes', 'Récurrence'];
    public const CONTACT_HEADERS = ['Nom', 'Prénom', 'Société', 'Téléphone', 'E-mail', 'Adresse', 'Notes'];
    public const STATUS = ['scheduled' => 'Planifié', 'confirmed' => 'Confirmé', 'tentative' => 'Provisoire', 'completed' => 'Terminé', 'cancelled' => 'Annulé'];
    public const PRIORITY = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
    public const FORMATS = ['csv' => ['text/csv; charset=utf-8', 'csv'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
        'pdf' => ['application/pdf', 'pdf'], 'ics' => ['text/calendar; charset=utf-8', 'ics']];

    public function __construct(private PDO $pdo, private PermissionService $perms, private AppointmentRepository $appointments, private ContactRepository $contacts)
    {
    }

    /**
     * @param array<string,mixed> $q format, from, to (AAAA-MM-JJ, fuseau de l'utilisateur), calendars (ids), status
     * @return array{body:string,type:string,ext:string,count:int}
     */
    public function appointments(AuthUser $u, array $q, ?DateTimeImmutable $now = null): array
    {
        $format = (string) ($q['format'] ?? 'csv');
        if (!isset(self::FORMATS[$format])) {
            throw new HttpException(422, 'Format invalide (csv, xlsx, pdf, ics).');
        }
        $tz = new DateTimeZone($u->timezone);
        $day = static function (mixed $v, string $name, bool $end) use ($tz): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new HttpException(422, "« $name » : date AAAA-MM-JJ attendue.");
            }
            $d = new DateTimeImmutable($v . ' 00:00:00', $tz);
            return ($end ? $d->modify('+1 day') : $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $from = $day($q['from'] ?? null, 'from', false);
        $to = $day($q['to'] ?? null, 'to', true);
        if ($from !== null && $to !== null && $to <= $from) {
            throw new HttpException(422, '« to » doit être postérieur ou égal à « from ».');
        }
        $status = $q['status'] ?? null;
        if ($status !== null && $status !== '' && (!is_string($status) || !in_array($status, AppointmentValidator::STATUSES, true))) {
            throw new HttpException(422, 'Statut invalide.');
        }
        $ids = $this->perms->readableCalendarIds($u);
        if (isset($q['calendars']) && $q['calendars'] !== '') {
            $asked = is_string($q['calendars']) ? explode(',', $q['calendars']) : [];
            foreach ($asked as $a) {
                if (!ctype_digit($a)) {
                    throw new HttpException(422, 'Paramètre « calendars » invalide.');
                }
            }
            $ids = array_values(array_intersect($ids, array_map('intval', $asked)));
        }
        $rows = $this->appointments->exportRows($ids, $from, $to, $status ?: null, self::MAX_ROWS);
        if (count($rows) > self::MAX_ROWS) {
            throw new HttpException(422, 'Trop de rendez-vous (' . self::MAX_ROWS . ' maximum par export) : réduisez la période ou les calendriers.');
        }
        $names = $this->pdo->query('SELECT id, name FROM calendars')->fetchAll(PDO::FETCH_KEY_PAIR);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($format === 'ics') {
            $exc = $this->appointments->exceptionsFor(array_map(static fn ($r) => (int) $r['id'], array_filter($rows, static fn ($r) => (bool) $r['recurring'])));
            $items = array_map(static fn ($r) => ['row' => $r, 'exceptions' => array_values($exc[(int) $r['id']] ?? [])], $rows);
            $body = Ics::write($items, 'RendezVous Manager', $now);
        } else {
            $table = array_map(fn ($r) => $this->line($r, (string) ($names[$r['calendar_id']] ?? '')), $rows);
            $body = match ($format) {
                'csv' => Csv::encode([self::APPOINTMENT_HEADERS, ...$table]),
                'xlsx' => XlsxWriter::build([self::APPOINTMENT_HEADERS, ...$table], 'Rendez-vous'),
                'pdf' => PdfWriter::build('Rendez-vous', count($rows) . ' rendez-vous · exporté le ' . $now->setTimezone($tz)->format('d/m/Y H:i') . ' (' . $u->timezone . ')',
                    ['Début', 'Fin', 'Titre', 'Calendrier', 'Lieu', 'Statut', 'Catégorie'],
                    array_map(static fn ($l) => [$l[2], $l[3], $l[0], $l[1], $l[10], $l[6], $l[8]], $table), [14, 14, 30, 16, 20, 9, 12]),
            };
        }
        return ['body' => $body, 'type' => self::FORMATS[$format][0], 'ext' => self::FORMATS[$format][1], 'count' => count($rows)];
    }

    /** @return array{body:string,type:string,ext:string,count:int} */
    public function contacts(AuthUser $u, string $format): array
    {
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new HttpException(422, 'Format invalide (csv, xlsx).');
        }
        $owner = $u->can('calendars.manage_all') ? null : $u->id;
        $res = $this->contacts->search($owner, '', 'name', 'asc', 1, self::MAX_ROWS + 1);
        if (count($res['rows']) > self::MAX_ROWS) {
            throw new HttpException(422, 'Trop de contacts (' . self::MAX_ROWS . ' maximum par export).');
        }
        $table = array_map(static fn ($c) => [(string) $c['last_name'], (string) $c['first_name'], (string) $c['company'], (string) $c['phone'], (string) $c['email'], (string) $c['address'], (string) $c['notes']], $res['rows']);
        $body = $format === 'csv' ? Csv::encode([self::CONTACT_HEADERS, ...$table]) : XlsxWriter::build([self::CONTACT_HEADERS, ...$table], 'Contacts');
        return ['body' => $body, 'type' => self::FORMATS[$format][0], 'ext' => $format, 'count' => count($table)];
    }

    /** @param array<string,mixed> $r @return list<string> */
    private function line(array $r, string $calendar): array
    {
        $allDay = (bool) $r['all_day'];
        $tz = new DateTimeZone($allDay ? 'UTC' : $r['timezone']);
        $s = new DateTimeImmutable($r['start_utc'], new DateTimeZone('UTC'));
        $e = new DateTimeImmutable($r['end_utc'], new DateTimeZone('UTC'));
        [$start, $end] = $allDay ? [$s->format('Y-m-d'), $e->modify('-1 day')->format('Y-m-d')]
            : [$s->setTimezone($tz)->format('Y-m-d H:i'), $e->setTimezone($tz)->format('Y-m-d H:i')];
        return [(string) $r['title'], $calendar, $start, $end, $allDay ? 'oui' : 'non', (string) $r['timezone'], self::STATUS[$r['status']], self::PRIORITY[$r['priority']],
            (string) ($r['category_name'] ?? ''), (string) ($r['contact_name'] ?? ''), (string) ($r['location'] ?? ''), (string) ($r['address'] ?? ''),
            (string) ($r['description'] ?? ''), (string) ($r['notes'] ?? ''), (string) ($r['rrule'] ?? '')];
    }
}
