<?php

namespace App\Support;

/**
 * Contrôles sur une carte bancaire saisie par une personne : réseau, longueur, clé de Luhn, code de sécurité, date d'expiration.
 *
 * Règle de sécurité : le numéro complet et le code de sécurité ne servent QU'À ces contrôles. Ils ne sont jamais enregistrés
 * (ni en base, ni dans une session, ni dans le journal) : on ne garde que le réseau, les 4 derniers chiffres, la date d'expiration,
 * le nom du titulaire et l'adresse de facturation, plus une empreinte à sens unique pour reconnaître une carte déjà ajoutée.
 */
final class CarteBancaire
{
    public const VISA = 'visa';

    public const MASTERCARD = 'mastercard';

    public const AMEX = 'amex';

    /** Ne garde que les chiffres : « 4242 4242-4242 4242 » devient « 4242424242424242 ». */
    public static function nettoyer(?string $numero): string
    {
        return preg_replace('/\D+/', '', (string) $numero) ?? '';
    }

    /** Le réseau se lit sur les premiers chiffres ; null si la carte n'est ni Visa, ni Mastercard, ni American Express. */
    public static function reseau(string $numero): ?string
    {
        $n = self::nettoyer($numero);

        if (preg_match('/^3[47]/', $n) === 1) {
            return self::AMEX;
        }

        if (preg_match('/^4/', $n) === 1) {
            return self::VISA;
        }

        // Mastercard : 51 à 55, ou 2221 à 2720.
        if (preg_match('/^5[1-5]/', $n) === 1 || ($n !== '' && strlen($n) >= 4 && (int) substr($n, 0, 4) >= 2221 && (int) substr($n, 0, 4) <= 2720)) {
            return self::MASTERCARD;
        }

        return null;
    }

    /** 15 chiffres pour American Express, 16 pour Visa et Mastercard. */
    public static function longueur(?string $reseau): int
    {
        return $reseau === self::AMEX ? 15 : 16;
    }

    /** 4 chiffres pour American Express, 3 pour les autres. */
    public static function longueurCvv(?string $reseau): int
    {
        return $reseau === self::AMEX ? 4 : 3;
    }

    /** Clé de contrôle de Luhn : détecte une faute de frappe dans le numéro. */
    public static function luhn(string $numero): bool
    {
        $n = self::nettoyer($numero);

        if ($n === '') {
            return false;
        }

        $somme = 0;
        $double = false;

        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $chiffre = (int) $n[$i];

            if ($double) {
                $chiffre *= 2;
                $chiffre -= $chiffre > 9 ? 9 : 0;
            }

            $somme += $chiffre;
            $double = ! $double;
        }

        return $somme % 10 === 0;
    }

    public static function numeroValide(string $numero): bool
    {
        $n = self::nettoyer($numero);
        $reseau = self::reseau($n);

        return $reseau !== null && strlen($n) === self::longueur($reseau) && self::luhn($n);
    }

    public static function cvvValide(?string $cvv, ?string $reseau): bool
    {
        return preg_match('/^\d{'.self::longueurCvv($reseau).'}$/', (string) $cvv) === 1;
    }

    /**
     * « MM/AA » (ou « MM/AAAA ») lu en [mois, année sur 4 chiffres] ; null si le format est faux.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function lireExpiration(?string $texte): ?array
    {
        if (preg_match('#^\s*(0[1-9]|1[0-2])\s*/\s*(\d{2}|\d{4})\s*$#', (string) $texte, $m) !== 1) {
            return null;
        }

        $annee = (int) $m[2];

        return [(int) $m[1], $annee < 100 ? 2000 + $annee : $annee];
    }

    /** Valable jusqu'à la fin du mois indiqué, et pas plus de N ans dans le futur (une date absurde est une faute de frappe). */
    public static function expirationValide(?string $texte, ?int $anneesMax = null): bool
    {
        $lue = self::lireExpiration($texte);

        if ($lue === null) {
            return false;
        }

        [$mois, $annee] = $lue;
        $anneesMax ??= (int) config('koudmain.cartes.expiration_max_annees', 10);
        $finDuMois = now()->setDate($annee, $mois, 1)->endOfMonth();

        return $finDuMois->greaterThanOrEqualTo(now()) && $finDuMois->lessThanOrEqualTo(now()->addYears($anneesMax)->endOfMonth());
    }

    /** « MM/AA » propre, prêt à afficher. */
    public static function formaterExpiration(string $texte): string
    {
        [$mois, $annee] = self::lireExpiration($texte) ?? [0, 0];

        return sprintf('%02d/%02d', $mois, $annee % 100);
    }

    /** Empreinte à sens unique (HMAC avec la clé de l'application) : sert seulement à reconnaître un numéro déjà ajouté. */
    public static function empreinte(string $numero): string
    {
        return hash_hmac('sha256', self::nettoyer($numero), (string) config('app.key'));
    }

    /** « •••• •••• •••• 4242 » (American Express : « •••• •••••• •4242 »). */
    public static function masque(string $numero, ?string $reseau = null): string
    {
        $n = self::nettoyer($numero);
        $fin = substr($n, -4);

        return ($reseau ?? self::reseau($n)) === self::AMEX ? '**** ****** *'.substr($fin, -4) : '**** **** **** '.$fin;
    }
}
