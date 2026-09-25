<?php

namespace App\Http\Resources\Api;

use App\Models\Paiement;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une recharge du wallet par Mobile Money (CinetPay). Tant qu'elle est « en_attente », « url_paiement » est la page CinetPay
 * à ouvrir dans l'application (WebView) ; l'application interroge ensuite GET /wallet/recharges/{reference} jusqu'au résultat.
 */
class PaiementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Paiement $p */
        $p = $this->resource;

        return [
            'reference' => $p->reference,
            'statut' => $p->statut, // en_attente | reussi | echoue | annule
            'methode' => $p->methode,
            'montant' => (int) round((float) $p->montant),
            'telephone_masque' => $p->telephone ? OtpService::masquer($p->telephone) : null,
            'url_paiement' => $p->statut === Paiement::EN_ATTENTE ? $p->url_paiement : null,
            'motif_echec' => $p->motif_echec,
            'cree_le' => $p->created_at?->toIso8601String(),
            'reussi_le' => $p->reussi_at?->toIso8601String(),
        ];
    }
}
