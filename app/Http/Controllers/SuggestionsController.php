<?php

namespace App\Http\Controllers;

use App\Support\Saisie;
use App\Services\RechercheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /recherche/suggestions?q=coif : quelques prestations pour la barre de recherche de l'accueil.
 * Réponse minimale (titre, prestataire, prix, adresse) : rien de privé.
 */
class SuggestionsController extends Controller
{
    public function __invoke(Request $request, RechercheService $recherche): JsonResponse
    {
        $prestations = $recherche->suggestions(Saisie::texte($request->query('q')), 5);

        return response()->json([
            'prestations' => $prestations->map(fn ($p) => [
                'id' => $p->id,
                'titre' => $p->titre,
                'prestataire' => $p->prestataire->nom_complet,
                'quartier' => $p->prestataire->quartier->nom,
                'metier' => $p->service->nom,
                'categorie' => $p->service->categorie->nom,
                'prix' => (int) round((float) $p->prix),
                'url' => route('prestations.voir', $p),
            ])->values(),
        ])->header('Cache-Control', 'private, max-age=30');
    }
}
