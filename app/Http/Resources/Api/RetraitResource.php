<?php

namespace App\Http\Resources\Api;

use App\Models\Retrait;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une demande de retrait d'un prestataire (traitée ensuite par un administrateur). */
class RetraitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Retrait $r */
        $r = $this->resource;
        $d = (string) $r->destination;

        return [
            'id' => $r->id,
            'montant' => (int) round((float) $r->montant),
            'methode' => $r->methode,
            'destination_masquee' => strlen($d) > 4 ? str_repeat('•', max(0, strlen($d) - 4)).substr($d, -4) : $d,
            'statut' => $r->statut, // en_attente | effectue | refuse
            'motif_refus' => $r->motif_refus,
            'demande_le' => $r->created_at?->toIso8601String(),
            'traite_le' => $r->traite_at?->toIso8601String(),
        ];
    }
}
