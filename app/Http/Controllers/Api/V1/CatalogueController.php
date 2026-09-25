<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PrestataireResumeResource;
use App\Http\Resources\Api\PrestationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Avis;
use App\Models\Prestation;
use App\Models\User;
use App\Services\DisponibiliteService;
use App\Services\RechercheService;
use App\Support\Duree;
use App\Support\RechercheCriteres;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le catalogue public : recherche/filtres, fiche d'une prestation, créneaux libres, profil d'un prestataire.
 * Mêmes règles que le site : seules les prestations actives de prestataires validés existent (sinon 404).
 */
class CatalogueController extends Controller
{
    public function __construct(private readonly RechercheService $recherche) {}

    /**
     * GET /prestations?q=&categorie=&service=&zone=v:1|q:12&prix_min=&prix_max=&note_min=3|4|4.5&photo=1&tri=&page=&par_page=
     * tri : pertinence (défaut avec q) | recent (défaut sans q) | prix_asc | prix_desc | note. Un filtre invalide est ignoré (jamais d'erreur).
     */
    public function index(Request $request): JsonResponse
    {
        $criteres = RechercheCriteres::depuis($request->query());
        $parPage = max(1, min(50, (int) $request->query('par_page', (string) config('koudmain.catalogue.par_page'))));

        return ApiResponse::pagine(
            $this->recherche->rechercher($criteres, $parPage),
            PrestationResource::class,
            meta: ['tri' => $criteres->tri, 'nombre_filtres' => $criteres->nombreFiltres()],
        );
    }

    public function show(Prestation $prestation, DisponibiliteService $disponibilites): JsonResponse
    {
        $this->visible($prestation);
        $prestation->load(['prestataire.quartier.ville', 'prestataire.avatar', 'service.categorie', 'medias']);

        $stats = Avis::query()->where('prestation_id', $prestation->id)
            ->selectRaw('COUNT(*) AS nombre, ROUND(AVG(note)::numeric, 1) AS moyenne')->first();
        $prestation->setAttribute('nb_avis', (int) ($stats->nombre ?? 0));
        $prestation->setAttribute('note_moy', $stats->moyenne ?? null);

        $prochain = $disponibilites->creneaux($prestation->prestataire, $prestation->duree_minutes ?? 60)[0] ?? null;

        return ApiResponse::succes((new PrestationResource($prestation))->resolve() + [
            'description' => $prestation->description,
            'photos' => $prestation->medias->map(fn ($m) => ['url' => $m->url(), 'largeur' => $m->largeur, 'hauteur' => $m->hauteur])->values()->all(),
            'avis' => $this->avis(Avis::query()->where('prestation_id', $prestation->id)),
            'prochain_creneau' => $prochain ? ['date' => $prochain['cle'], 'heure' => $prochain['creneaux'][0], 'libelle' => $prochain['long']] : null,
            'horaires' => $disponibilites->horaires($prestation->prestataire),
        ]);
    }

    /** Les jours (14 à venir) et heures de début encore libres pour CETTE prestation (sa durée compte). */
    public function creneaux(Prestation $prestation, DisponibiliteService $disponibilites): JsonResponse
    {
        $this->visible($prestation);
        $prestation->loadMissing('prestataire');

        $jours = $disponibilites->creneaux($prestation->prestataire, $prestation->duree_minutes ?? 60);

        return ApiResponse::succes([
            'duree_minutes' => $prestation->duree_minutes,
            'duree_libelle' => Duree::libelle($prestation->duree_minutes),
            'jours' => array_map(fn (array $j) => ['date' => $j['cle'], 'libelle' => $j['long'], 'libelle_court' => $j['court'], 'heures' => $j['creneaux']], $jours),
        ]);
    }

    /** Profil public d'un prestataire validé : résumé, note globale, ses prestations (paginées), derniers avis. */
    public function prestataire(User $prestataire): JsonResponse
    {
        // {prestataire} est résolu par Route::bind (AppServiceProvider) : seul un prestataire VALIDÉ est trouvé, sinon 404.
        $p = $prestataire->load(['quartier.ville', 'avatar']);

        $notes = Avis::query()->join('prestations', 'prestations.id', '=', 'avis.prestation_id')
            ->where('prestations.prestataire_id', $p->id)
            ->selectRaw('COUNT(*) AS nombre, ROUND(AVG(avis.note)::numeric, 1) AS moyenne')->first();

        $page = $this->recherche->rechercher(new RechercheCriteres(prestataire: $p->id), 20);

        return ApiResponse::succes([
            'prestataire' => (new PrestataireResumeResource($p))->resolve() + ['bio' => $p->bio],
            'note' => ($notes->nombre ?? 0) > 0 ? (float) $notes->moyenne : null,
            'nb_avis' => (int) ($notes->nombre ?? 0),
            'prestations' => PrestationResource::collection($page->getCollection())->resolve(),
            'avis' => $this->avis(Avis::query()->select('avis.*')->join('prestations', 'prestations.id', '=', 'avis.prestation_id')->where('prestations.prestataire_id', $p->id)),
        ]);
    }

    /** Une prestation masquée, ou d'un prestataire non validé, n'existe pas pour l'application : 404. */
    private function visible(Prestation $prestation): void
    {
        $prestation->loadMissing('prestataire');

        abort_unless($prestation->est_active && $prestation->prestataire->aLeRole('prestataire'), 404);
    }

    /** Les 10 derniers avis (auteur réduit au prénom et à l'initiale du nom). @return list<array<string, mixed>> */
    private function avis($requete): array
    {
        return $requete->with('client:id,prenom,nom')->latest('avis.created_at')->limit(10)->get()
            ->map(fn (Avis $a) => [
                'note' => $a->note,
                'commentaire' => $a->commentaire,
                'auteur' => trim(($a->client?->prenom ?? 'Client').' '.mb_substr((string) $a->client?->nom, 0, 1).'.'),
                'date' => $a->created_at?->toIso8601String(),
            ])->values()->all();
    }
}
