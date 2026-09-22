<?php

namespace App\Events;

use App\Models\Retrait;
use Illuminate\Foundation\Events\Dispatchable;

/** Un administrateur a confirmé ou refusé un retrait (le lot 4 prévient le prestataire). */
class RetraitTraite
{
    use Dispatchable;

    public function __construct(public readonly Retrait $retrait)
    {
    }
}
