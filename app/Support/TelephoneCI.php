<?php

namespace App\Support;

/**
 * Numéros ivoiriens : 10 chiffres commençant par 01, 05, 07 (mobiles) ou 21, 25, 27 (fixes).
 * Espaces, points, tirets, parenthèses et préfixe +225 / 00225 sont tolérés à la saisie.
 */
final class TelephoneCI
{
    public const MOTIF = '/^(01|05|07|21|25|27)[0-9]{8}$/';

    public static function normaliser(?string $tel): string
    {
        $tel = preg_replace('/[\s.\-()]/', '', (string) $tel) ?? '';

        return preg_replace('/^(\+|00)225/', '', $tel) ?? '';
    }

    public static function estValide(?string $tel): bool
    {
        return preg_match(self::MOTIF, self::normaliser($tel)) === 1;
    }
}
