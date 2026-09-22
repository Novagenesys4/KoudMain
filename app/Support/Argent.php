<?php

namespace App\Support;

/**
 * Les montants circulent en CENTIMES entiers dans le code de calcul (jamais en flottants) :
 * 0.1 + 0.2 n'est pas 0.3 en virgule flottante, et un centime perdu sur un wallet est une erreur de comptabilité.
 * La base stocke des DECIMAL(12,2) ; ce sont les seules conversions.
 */
final class Argent
{
    /** 1234.5 / « 1234.50 » -> 123450 */
    public static function centimes(int|float|string|null $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    /** 123450 -> « 1234.50 » (format attendu par PostgreSQL) */
    public static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
