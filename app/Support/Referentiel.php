<?php

namespace App\Support;

use App\Models\Categorie;
use App\Models\Quartier;
use App\Models\Service;
use App\Models\Ville;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Les listes qui ne changent presque jamais : catégories avec leurs services, villes avec leurs quartiers.
 *
 * Elles servent à presque toutes les pages (filtres du catalogue, formulaires, page d'accueil) : les relire à chaque visite
 * coûtait 4 à 6 requêtes SQL, donc autant d'allers-retours vers la base. On les garde 30 minutes en cache ;
 * dès que l'administrateur ajoute ou supprime une catégorie, un service, une ville ou un quartier, le cache est vidé
 * (voir AppServiceProvider), donc personne ne voit une liste périmée.
 */
final class Referentiel
{
    private const DUREE = 1800;

    private const CLES = ['referentiel.categories', 'referentiel.villes'];

    /** Les catégories (par ordre alphabétique) avec leurs services (idem), prêts pour l'affichage. */
    public static function categories(): Collection
    {
        return Cache::remember(self::CLES[0], self::DUREE, fn () => Categorie::query()
            ->with(['services' => fn ($q) => $q->orderBy('nom')])->orderBy('nom')->get());
    }

    /** Les catégories qui ont au moins un service (celles qu'un prestataire peut choisir). */
    public static function categoriesAvecServices(): Collection
    {
        return self::categories()->filter(fn (Categorie $c) => $c->services->isNotEmpty())->values();
    }

    /** Les villes avec leurs quartiers (par ordre alphabétique). */
    public static function villes(): Collection
    {
        return Cache::remember(self::CLES[1], self::DUREE, fn () => Ville::query()
            ->with(['quartiers' => fn ($q) => $q->orderBy('nom')])->orderBy('nom')->get());
    }

    public static function oublier(): void
    {
        foreach (self::CLES as $cle) {
            Cache::forget($cle);
        }
    }

    /** À appeler une fois au démarrage : toute modification d'une de ces tables vide le cache. */
    public static function surveiller(): void
    {
        foreach ([Categorie::class, Service::class, Ville::class, Quartier::class] as $modele) {
            $modele::saved(fn () => self::oublier());
            $modele::deleted(fn () => self::oublier());
        }
    }
}
