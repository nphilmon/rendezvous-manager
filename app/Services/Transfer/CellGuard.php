<?php
declare(strict_types=1);

namespace App\Services\Transfer;

/**
 * Protection contre l'injection de formules (CSV/XLSX/PDF ouverts dans un tableur) : toute cellule de texte qui commence par
 * « = + - @ », une tabulation ou un retour chariot reçoit une apostrophe de tête, qui force le tableur à l'afficher comme du texte.
 * À l'import, cette apostrophe protectrice est retirée pour que l'export puis l'import restituent la valeur d'origine.
 */
final class CellGuard
{
    public static function out(string $v): string
    {
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }

    public static function in(string $v): string
    {
        return strlen($v) >= 2 && $v[0] === "'" && strpbrk($v[1], "=+-@\t\r") !== false ? substr($v, 1) : $v;
    }
}
