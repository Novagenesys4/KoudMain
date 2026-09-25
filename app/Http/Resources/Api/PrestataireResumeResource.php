<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Un prestataire tel que le voit un client : ni e-mail ni téléphone (les échanges passent par la messagerie). */
class PrestataireResumeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'prenom' => $p->prenom,
            'nom_complet' => $p->nom_complet,
            'initiales' => mb_strtoupper(mb_substr((string) $p->prenom, 0, 1).mb_substr((string) $p->nom, 0, 1)),
            'avatar_url' => $p->relationLoaded('avatar') ? $p->avatar?->url() : null,
            'verifie' => (bool) ($p->est_prestataire && $p->est_valide),
            'quartier' => $p->relationLoaded('quartier') ? $p->quartier?->nom : null,
            'ville' => $p->relationLoaded('quartier') && $p->quartier?->relationLoaded('ville') ? $p->quartier->ville?->nom : null,
            'membre_depuis' => $p->created_at?->year,
        ];
    }
}
