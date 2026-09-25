<?php

namespace App\Http\Resources\Api;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une ligne de l'historique du wallet (registre immuable). */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WalletTransaction $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'type' => $t->type, // credit | debit | retrait
            'sens' => $t->type === 'credit' ? 'entree' : 'sortie',
            'montant' => (int) round((float) $t->montant),
            'libelle' => $t->libelle,
            'solde_apres' => (int) round((float) $t->solde_apres),
            'commande_id' => $t->commande_id,
            'date' => $t->created_at?->toIso8601String(),
        ];
    }
}
