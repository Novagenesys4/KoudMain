<?php

namespace App\Support\Espace;

/**
 * Les onglets de l'espace dont le fonctionnement arrive dans un prochain lot.
 * Ils sont déjà dans le menu (l'organisation est celle de l'ancienne application), mais leur page dit
 * honnêtement ce qui est prévu, sans fausse donnée. Quand un lot est livré, on remplace la route
 * par le vrai contrôleur et on retire l'entrée ici.
 *
 * Clé = nom de la route. `action` = où renvoyer le visiteur en attendant (clé de route + libellé).
 */
final class Bientot
{
    /** @var array<string, array{titre: string, icone: string, lot: int, resume: string, prevu: list<string>, action: array{route: string, libelle: string}|null}> */
    private const PAGES = [];

    /** @return array{titre: string, icone: string, lot: int, resume: string, prevu: list<string>, action: array{route: string, libelle: string}|null}|null */
    public static function page(string $route): ?array
    {
        return self::PAGES[$route] ?? null;
    }

    /** @return list<string> noms des routes concernées */
    public static function routes(): array
    {
        return array_keys(self::PAGES);
    }
}
