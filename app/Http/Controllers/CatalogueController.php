<?php

namespace App\Http\Controllers;

use App\Services\RechercheService;
use App\Support\Presentateur;
use App\Support\RechercheCriteres;
use App\Support\Referentiel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /prestations : le catalogue public (recherche, filtres, tris, pagination).
 * L'espace client réutilise ce contrôleur (Client\CatalogueController) : même recherche, autre gabarit.
 */
class CatalogueController extends Controller
{
    protected string $vue = 'catalogue.index';

    /** Nom de la route de la page : les formulaires de filtres et les liens y reviennent. */
    protected string $nomRoute = 'catalogue';

    public function index(Request $request, RechercheService $recherche): View
    {
        $criteres = RechercheCriteres::depuis($request->query());
        $page = $recherche->rechercher($criteres);
        $favoris = $this->favoris($request, $page->getCollection()->pluck('id')->all());

        return view($this->vue, [
            'nomRoute' => $this->nomRoute,
            'criteres' => $criteres,
            'page' => $page,
            'cartes' => $page->getCollection()->map(fn ($prestation) => Presentateur::carte($prestation, $favoris))->values()->all(),
            'categories' => Referentiel::categories(),
            'villes' => Referentiel::villes(),
        ]);
    }

    /**
     * Les prestations de cette page qui sont déjà en favori. Le catalogue public n'a pas de cœur (null) ;
     * l'espace client le remplace (Client\CatalogueController).
     *
     * @param  list<int>  $prestationIds
     * @return list<int>|null
     */
    protected function favoris(Request $request, array $prestationIds): ?array
    {
        return null;
    }
}
