<?php

namespace App\Services\Paiement;

use App\Models\Paiement;
use App\Models\User;

/**
 * FAUX paiement : la recharge réussit aussitôt, sans aucun argent réel. Réservé au développement et aux démonstrations
 * (PAIEMENT_DRIVER=simulation). En production, le pilote par défaut est « aucun » : on ne prétend jamais encaisser.
 */
class PaiementSimulation implements FournisseurPaiement
{
    public function nom(): string
    {
        return 'simulation';
    }

    public function initier(Paiement $paiement, User $client): ResultatPaiement
    {
        return ResultatPaiement::reussi((float) $paiement->montant, 'SIM-'.$paiement->reference);
    }

    public function verifier(Paiement $paiement): ResultatPaiement
    {
        return ResultatPaiement::reussi((float) $paiement->montant, 'SIM-'.$paiement->reference);
    }
}
