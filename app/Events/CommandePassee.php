<?php

namespace App\Events;

use App\Models\Commande;
use Illuminate\Foundation\Events\Dispatchable;

/** Une commande vient d'être créée (envoyé APRÈS la validation de la transaction). Le lot 4 y branche les notifications. */
class CommandePassee
{
    use Dispatchable;

    public function __construct(public readonly Commande $commande)
    {
    }
}
