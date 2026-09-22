<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quartier extends Model
{
    public $timestamps = false;

    protected $fillable = ['nom'];

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class);
    }
}
