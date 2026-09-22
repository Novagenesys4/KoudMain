<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une plage d'ouverture hebdomadaire d'un prestataire (jour 1 = lundi ... 7 = dimanche). */
class Disponibilite extends Model
{
    protected $fillable = ['jour', 'heure_debut', 'heure_fin'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
