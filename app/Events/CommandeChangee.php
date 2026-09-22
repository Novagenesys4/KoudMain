<?php

namespace App\Events;

use App\Models\Commande;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une commande a changé d'état (envoyé APRÈS la validation de la transaction).
 * $evenement : accepter, demarrer, terminer, annuler, ouvrir_litige, confirmer_reception,
 * liberation_auto, arbitrage_liberer ou arbitrage_rembourser. $acteur est null pour la libération automatique.
 */
class CommandeChangee
{
    use Dispatchable;

    public function __construct(public readonly Commande $commande, public readonly string $evenement, public readonly ?User $acteur = null)
    {
    }
}
