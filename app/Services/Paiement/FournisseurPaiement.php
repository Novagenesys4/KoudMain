<?php

namespace App\Services\Paiement;

use App\Models\Paiement;
use App\Models\User;

/**
 * Un agrégateur de paiement Mobile Money (CinetPay, PayDunya...). PaiementService ne connaît que ce contrat :
 * changer d'agrégateur = écrire une classe et changer PAIEMENT_DRIVER.
 */
interface FournisseurPaiement
{
    /** Nom stocké dans paiements.fournisseur. */
    public function nom(): string;

    /** Ouvre le paiement chez l'agrégateur. Renvoie soit « réussi » tout de suite, soit une page de paiement à ouvrir. */
    public function initier(Paiement $paiement, User $client): ResultatPaiement;

    /**
     * Demande à l'agrégateur l'état RÉEL du paiement (jamais confiance à ce qu'envoie le navigateur ou le webhook :
     * n'importe qui peut appeler notre adresse de notification).
     */
    public function verifier(Paiement $paiement): ResultatPaiement;
}
