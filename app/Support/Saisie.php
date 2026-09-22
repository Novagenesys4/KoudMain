<?php

namespace App\Support;

/**
 * Lecture sûre d'une entrée du visiteur (règle 10). Tout ce qui arrive dans l'adresse ou un formulaire est NON FIABLE :
 * ?q[]=x fournit un tableau là où l'on attend un texte, et (string) d'un tableau provoque une erreur serveur.
 * Ces fonctions renvoient toujours une valeur du bon type, sans exception.
 */
final class Saisie
{
    /** La valeur telle quelle, mais toujours sous forme de texte : un tableau, un objet ou un booléen deviennent ''. (À utiliser à la place de (string).) */
    public static function chaine(mixed $valeur): string
    {
        return is_scalar($valeur) && ! is_bool($valeur) ? (string) $valeur : '';
    }

    /** Un texte court : les tableaux et objets deviennent '', les espaces sont retirés, la longueur est plafonnée (en caractères). */
    public static function texte(mixed $valeur, int $max = 100): string
    {
        if (! is_scalar($valeur) || is_bool($valeur)) {
            return '';
        }

        // Octets nuls et caractères de contrôle : jamais utiles dans une recherche ou un filtre, parfois dangereux pour les bases et les journaux.
        $texte = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $valeur);

        return mb_substr(trim($texte), 0, max(0, $max));
    }

    /** Un texte qui doit faire partie d'une liste : sinon la valeur par défaut. @param list<string> $permis */
    public static function choix(mixed $valeur, array $permis, string $defaut = ''): string
    {
        $texte = self::texte($valeur, 64);

        return in_array($texte, $permis, true) ? $texte : $defaut;
    }

    /** Un entier positif borné, ou null. */
    public static function entier(mixed $valeur, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        if (! is_scalar($valeur) || is_bool($valeur) || ! preg_match('/^\d{1,18}$/', trim((string) $valeur))) {
            return null;
        }

        $n = (int) trim((string) $valeur);

        return ($n < $min || $n > $max) ? null : $n;
    }
}
