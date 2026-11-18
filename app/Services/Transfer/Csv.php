<?php
declare(strict_types=1);

namespace App\Services\Transfer;

use App\Core\HttpException;

/** CSV RFC 4180 : UTF-8 avec BOM (lisible par Excel), fins de ligne CRLF, cellules protégées contre l'injection de formules. */
final class Csv
{
    /** @param list<list<string>> $rows en-tête comprise */
    public static function encode(array $rows, string $delimiter = ','): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $i => $row) {
            $out .= implode($delimiter, array_map(static function (string $c) use ($delimiter, $i): string {
                $c = $i === 0 ? $c : CellGuard::out($c);
                return preg_match('/[",\r\n;\t]/', $c) || $c !== trim($c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
            }, $row)) . "\r\n";
        }
        return $out;
    }

    /** @return list<list<string>> lignes vides ignorées ; séparateur détecté (; , tabulation) ; Windows-1252 accepté */
    public static function decode(string $s): array
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string) iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        }
        if (str_contains($s, "\0")) {
            throw new HttpException(422, 'Fichier non reconnu comme du texte CSV.');
        }
        $first = strtok($s, "\r\n") ?: '';
        $delim = ',';
        $best = -1;
        foreach ([',', ';', "\t"] as $d) {
            $n = count(str_getcsv($first, $d, '"', ''));
            if ($n > $best) {
                $best = $n;
                $delim = $d;
            }
        }
        $rows = [];
        $row = [];
        $cell = '';
        $q = false;
        $n = strlen($s);
        $touched = false;
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($q) {
                if ($c === '"') {
                    if (($s[$i + 1] ?? '') === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $q = false;
                    }
                } else {
                    $cell .= $c;
                }
                continue;
            }
            if ($c === '"' && $cell === '') {
                $q = true;
                $touched = true;
            } elseif ($c === $delim) {
                $row[] = $cell;
                $cell = '';
                $touched = true;
            } elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($s[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $cell;
                if ($touched || $cell !== '' || count($row) > 1) {
                    $rows[] = $row;
                }
                $row = [];
                $cell = '';
                $touched = false;
            } else {
                $cell .= $c;
            }
        }
        if ($q) {
            throw new HttpException(422, 'CSV invalide : guillemet non fermé.');
        }
        $row[] = $cell;
        if ($touched || $cell !== '' || count($row) > 1) {
            $rows[] = $row;
        }
        return array_values(array_filter($rows, static fn (array $r) => count(array_filter($r, static fn ($c) => trim($c) !== '')) > 0));
    }
}
