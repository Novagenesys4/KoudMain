<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RechercheService;
use App\Support\Presentateur;
use App\Support\Referentiel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccueilController extends Controller
{
    public function __invoke(RechercheService $recherche): View
    {
        // Les catégories (et leurs services) viennent du cache : elles ne changent presque jamais.
        $categories = Referentiel::categories();

        // Chiffres affichés sur la page d'accueil : des comptages réels, jamais des valeurs écrites à la main.
        // La page n'affiche pas un chiffre à zéro (voir Chiffres.jsx).
        $stats = [
            ['valeur' => $categories->count(), 'libelle' => 'Domaines de services'],
            ['valeur' => (int) $categories->sum(fn ($c) => $c->services->count()), 'libelle' => 'Types de services'],
            ['valeur' => (int) Referentiel::villes()->sum(fn ($v) => $v->quartiers->count()), 'libelle' => 'Quartiers référencés'],
        ];

        // Ce qui « vit » sur la plateforme (dernières prestations, quartiers desservis, prestataires vérifiés) : gardé 60 secondes,
        // la page d'accueil est la plus visitée et une nouveauté peut attendre une minute.
        $vivant = Cache::remember('accueil.vivant', 60, fn () => [
            'prestations' => $recherche->recentes(6)->map(fn ($prestation) => Presentateur::carte($prestation))->values()->all(),
            // Les quartiers où au moins un prestataire validé propose une prestation active (pour le bandeau défilant).
            'quartiers' => DB::table('prestations as p')
                ->join('users as u', 'u.id', '=', 'p.prestataire_id')
                ->join('quartiers as q', 'q.id', '=', 'u.quartier_id')
                ->where('p.est_active', true)->where('u.est_prestataire', true)->where('u.est_valide', true)
                ->distinct()->orderBy('q.nom')->limit(16)->pluck('q.nom')->all(),
            'prestataires' => User::query()->where('est_prestataire', true)->where('est_valide', true)->count(),
        ]);

        return view('accueil', [
            'categories' => $categories,
            'stats' => $stats,
            'prestations' => $vivant['prestations'],
            'quartiers' => $vivant['quartiers'],
            'nbPrestataires' => $vivant['prestataires'],
        ]);
    }
}
