<?php

namespace App\Events;

use App\Models\Retrait;
use Illuminate\Foundation\Events\Dispatchable;

/** Un prestataire vient de demander un retrait (envoyé APRÈS la validation de la transaction) : les administrateurs en sont prévenus. */
class RetraitDemande
{
    use Dispatchable;

    public function __construct(public readonly Retrait $retrait)
    {
    }
}
