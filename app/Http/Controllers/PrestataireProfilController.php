<?php

namespace App\Http\Controllers;

use App\Models\Avis;
use App\Models\User;
use App\Services\RechercheService;
use App\Support\Presentateur;
use App\Support\RechercheCriteres;
use Illuminate\View\View;

/**
 * /prestataires/12 : profil public d'un prestataire validé (le modèle est retrouvé par Route::bind, voir AppServiceProvider).
 * On n'affiche NI e-mail NI téléphone : les échanges passeront par la messagerie (lot 4).
 */
class PrestataireProfilController extends Controller
{
    public function show(User $prestataire, RechercheService $recherche): View
    {
        $prestataire->load(['quartier.ville', 'avatar']);

        $page = $recherche->rechercher(new RechercheCriteres(prestataire: $prestataire->id));

        $notes = Avis::query()
            ->join('prestations', 'prestations.id', '=', 'avis.prestation_id')
            ->where('prestations.prestataire_id', $prestataire->id)
            ->selectRaw('COUNT(*) AS nombre, ROUND(AVG(avis.note)::numeric, 1) AS moyenne')
            ->first();

        $avis = Avis::query()
            ->select('avis.*')
            ->join('prestations', 'prestations.id', '=', 'avis.prestation_id')
            ->where('prestations.prestataire_id', $prestataire->id)
            ->with(['client:id,prenom,nom', 'prestation:id,titre,slug'])
            ->latest('avis.created_at')
            ->limit(10)
            ->get();

        return view('prestataires.show', [
            'prestataire' => $prestataire,
            'page' => $page,
            'cartes' => $page->getCollection()->map(fn ($prestation) => Presentateur::carte($prestation))->values()->all(),
            'nombreAvis' => (int) ($notes->nombre ?? 0),
            'moyenne' => $notes && $notes->nombre > 0 ? (float) $notes->moyenne : null,
            'avis' => $avis,
        ]);
    }
}
