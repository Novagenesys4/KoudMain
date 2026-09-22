<?php

namespace App\Http\Controllers;

use App\Models\Avis;
use App\Models\Prestation;
use App\Services\DisponibiliteService;
use App\Services\RechercheService;
use App\Support\Duree;
use App\Support\Presentateur;
use App\Support\RechercheCriteres;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /prestations/coiffure-tresses-k3x9ab : la page publique d'une prestation. */
class PrestationPubliqueController extends Controller
{
    public function show(Request $request, Prestation $prestation, RechercheService $recherche, DisponibiliteService $disponibilites): View
    {
        $prestation->load(['prestataire.quartier.ville', 'prestataire.avatar', 'service.categorie', 'medias']);

        $prestataire = $prestation->prestataire;
        $utilisateur = $request->user();

        $visiblePourTous = $prestation->est_active && $prestataire->est_prestataire && $prestataire->est_valide;
        $estProprietaire = $utilisateur !== null && $utilisateur->id === $prestation->prestataire_id;

        // Une prestation masquée n'existe pas pour le public (404) ; son prestataire et les administrateurs la voient.
        if (! $visiblePourTous && ! $estProprietaire && ! $utilisateur?->est_admin) {
            abort(404);
        }

        $avis = $prestation->avis()->with('client:id,prenom,nom')->latest()->limit(20)->get();
        $statistiques = Avis::query()
            ->where('prestation_id', $prestation->id)
            ->selectRaw('COUNT(*) AS nombre, ROUND(AVG(note)::numeric, 1) AS moyenne')
            ->first();

        $ailleurs = fn (RechercheCriteres $criteres, array $sauf = []) => $recherche->requete($criteres)
            ->whereNotIn('prestations.id', [$prestation->id, ...$sauf])
            ->limit(3)
            ->get()
            ->map(fn ($p) => Presentateur::carte($p))
            ->all();

        $memePrestataire = $ailleurs(new RechercheCriteres(prestataire: $prestataire->id));

        // Quand est-il libre ? Les horaires de la semaine, et le prochain créneau réellement libre (commandes acceptées déduites).
        $horaires = $visiblePourTous ? $disponibilites->horaires($prestataire) : [];
        $prochain = null;

        if ($visiblePourTous) {
            $premierJour = $disponibilites->creneaux($prestataire, $prestation->duree_minutes ?? 60)[0] ?? null;
            $prochain = $premierJour ? ['jour' => $premierJour['long'], 'heure' => $premierJour['creneaux'][0]] : null;
        }

        return view('prestations.show', [
            'prestation' => $prestation,
            'prestataire' => $prestataire,
            'photos' => $prestation->medias->map(fn ($photo) => ['url' => $photo->url(), 'largeur' => $photo->largeur, 'hauteur' => $photo->hauteur])->all(),
            'duree' => Duree::libelle($prestation->duree_minutes),
            'avis' => $avis,
            'nombreAvis' => (int) ($statistiques->nombre ?? 0),
            'moyenne' => $statistiques && $statistiques->nombre > 0 ? (float) $statistiques->moyenne : null,
            'horaires' => $horaires,
            'jours' => DisponibiliteService::JOURS,
            'prochainCreneau' => $prochain,
            'estProprietaire' => $estProprietaire,
            'visiblePourTous' => $visiblePourTous,
            'favori' => $utilisateur !== null && $utilisateur->aLeRole('client') && $visiblePourTous && ! $estProprietaire
                ? in_array($prestation->id, app(\App\Services\FavoriService::class)->parmi($utilisateur, [$prestation->id]), true)
                : null, // null : pas de bouton « favori » (visiteur, prestataire, prestation masquée)
            'memePrestataire' => $memePrestataire,
            // Pas de doublon avec le bloc précédent.
            'memeCategorie' => $ailleurs(new RechercheCriteres(categorie: $prestation->service->categorie_id), array_column($memePrestataire, 'id')),
        ]);
    }
}
