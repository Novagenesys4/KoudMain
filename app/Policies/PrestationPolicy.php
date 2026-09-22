<?php

namespace App\Policies;

use App\Models\Prestation;
use App\Models\User;

class PrestationPolicy
{
    /**
     * Modifier, masquer, supprimer, gérer les photos : réservé au prestataire propriétaire (et validé).
     * Utilisé par Gate::authorize('gerer', $prestation).
     */
    public function gerer(User $utilisateur, Prestation $prestation): bool
    {
        return $utilisateur->est_prestataire
            && $utilisateur->est_valide
            && $prestation->prestataire_id === $utilisateur->id;
    }
}
