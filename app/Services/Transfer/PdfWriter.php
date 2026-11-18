<?php
declare(strict_types=1);

namespace App\Services\Transfer;

/**
 * PDF tabulaire minimal (A4 paysage, Helvetica, WinAnsi), sans bibliothèque. Le texte est du texte brut : une formule n'y est jamais évaluée,
 * mais la même protection d'apostrophe est appliquée pour rester cohérent avec les autres formats. Les cellules trop longues sont tronquées (« .. »).
 */
final class PdfWriter
{
    private const W = 842;
    private const H = 595;
    private const M = 30;
    private const SIZE = 8;
    private const ROW = 13;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<int> $widths largeurs relatives des colonnes
     */
    public static function build(string $title, string $subtitle, array $headers, array $rows, array $widths): string
    {
        $total = array_sum($widths);
        $usable = self::W - 2 * self::M;
        $cols = [];
        $x = self::M;
        foreach ($widths as $w) {
            $px = $usable * $w / $total;
            $cols[] = [$x, $px];
            $x += $px;
        }
        $perPage = (int) floor((self::H - 2 * self::M - 50) / self::ROW);
        $chunks = array_chunk($rows, max(1, $perPage)) ?: [[]];
        $pages = [];
        foreach ($chunks as $n => $chunk) {
            $s = '';
            $y = self::H - self::M;
            $s .= self::text(self::M, $y, $title, 14, true);
            $s .= self::text(self::W - self::M - 160, $y, 'Page ' . ($n + 1) . '/' . count($chunks), self::SIZE, false);
            $y -= 14;
            $s .= self::text(self::M, $y, $subtitle, self::SIZE, false);
            $y -= 20;
            foreach ($headers as $i => $h) {
                $s .= self::text($cols[$i][0], $y, self::fit($h, $cols[$i][1]), self::SIZE, true);
            }
            $s .= sprintf("0.5 w %.2F %.2F m %.2F %.2F l S\n", self::M, $y - 3, self::W - self::M, $y - 3);
            $y -= self::ROW;
            foreach ($chunk as $row) {
                foreach ($row as $i => $v) {
                    $s .= self::text($cols[$i][0], $y, self::fit(CellGuard::out($v), $cols[$i][1]), self::SIZE, false);
                }
                $y -= self::ROW;
            }
            $pages[] = $s;
        }
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $i => $content) {
            $cid = 5 + $i * 2;
            $pid = $cid + 1;
            $objs[$cid] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objs[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $cid . ' 0 R >>';
            $kids[] = $pid . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $n => $body) {
            $off[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        }
        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    private static function fit(string $s, float $width): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $max = max(1, (int) floor($width / (self::SIZE * 0.52)) - 1);
        return mb_strlen($s) > $max ? mb_substr($s, 0, max(1, $max - 2)) . '..' : $s;
    }

    private static function text(float $x, float $y, string $s, int $size, bool $bold): string
    {
        $enc = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        $enc = preg_replace('/[\x00-\x1F\x7F]/', ' ', $enc) ?? '';
        $enc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $enc);
        return sprintf("BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $enc);
    }
}
