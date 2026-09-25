<?php

namespace App\Http\Resources\Api;

use App\Models\Prestation;
use App\Support\Duree;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une prestation du catalogue. Chargée avec RechercheService::RELATIONS (+ colonnes note_moy et nb_avis de la recherche) ;
 * la fiche détaillée ajoute description, toutes les photos et les avis (voir CatalogueController::show).
 */
class PrestationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Prestation $p */
        $p = $this->resource;
        $nbAvis = (int) ($p->nb_avis ?? 0);
        $service = $p->relationLoaded('service') ? $p->service : null;

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'titre' => $p->titre,
            'prix' => (int) round((float) $p->prix),
            'duree_minutes' => $p->duree_minutes,
            'duree_libelle' => Duree::libelle($p->duree_minutes),
            'note' => $nbAvis > 0 ? round((float) $p->note_moy, 1) : null,
            'nb_avis' => $nbAvis,
            'photo_url' => $p->relationLoaded('medias') ? $p->medias->first()?->url() : null,
            'service' => $service ? ['id' => $service->id, 'nom' => $service->nom] : null,
            'categorie' => $service && $service->relationLoaded('categorie') ? ['id' => $service->categorie->id, 'nom' => $service->categorie->nom] : null,
            'prestataire' => $p->relationLoaded('prestataire') ? new PrestataireResumeResource($p->prestataire) : null,
        ];
    }
}
