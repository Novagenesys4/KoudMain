<?php

namespace App\Support;

/** Mise en forme des nombres pour l'affichage (les vues n'ont pas à connaître les séparateurs français). */
final class Format
{
    /** 15000 -> « 15 000 » (espace insécable : le montant ne se coupe jamais en fin de ligne). */
    public static function montant(int|float|string|null $valeur): string
    {
        return number_format((float) $valeur, 0, ',', "\u{202F}");
    }

    /** 15000 -> « 15 000 FCFA » */
    public static function fcfa(int|float|string|null $valeur): string
    {
        return self::montant($valeur)."\u{00A0}".config('koudmain.devise');
    }

    /** 4.85 -> « 4,9 » */
    public static function note(float $valeur): string
    {
        return number_format($valeur, 1, ',', '');
    }

    public static function pluriel(int $nombre, string $singulier, ?string $pluriel = null): string
    {
        return $nombre.' '.($nombre > 1 ? ($pluriel ?? $singulier.'s') : $singulier);
    }

    /** 12500 -> « 12,5 k », 2300000 -> « 2,3 M » : pour les axes des graphiques, où la place manque. */
    public static function court(int|float $valeur): string
    {
        $v = abs((float) $valeur);
        $signe = $valeur < 0 ? '-' : '';

        if ($v >= 1_000_000) {
            return $signe.rtrim(rtrim(number_format($v / 1_000_000, 1, ',', ''), '0'), ',').' M';
        }

        if ($v >= 1000) {
            return $signe.rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',').' k';
        }

        return $signe.number_format($v, 0, ',', '');
    }

    /** 45 -> « 45 s », 830 -> « 14 min », 7800 -> « 2 h 10 », 200000 -> « 2 j 7 h ». */
    public static function duree(int|float $secondes): string
    {
        $s = (int) round($secondes);

        return match (true) {
            $s < 60 => $s.' s',
            $s < 3600 => intdiv($s, 60).' min',
            $s < 86400 => intdiv($s, 3600).' h'.(($m = intdiv($s % 3600, 60)) > 0 ? ' '.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : ''),
            default => intdiv($s, 86400).' j'.(($h = intdiv($s % 86400, 3600)) > 0 ? ' '.$h.' h' : ''),
        };
    }
}
