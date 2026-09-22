<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departement extends Model
{
    public $timestamps = false;

    protected $fillable = ['nom'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function villes(): HasMany
    {
        return $this->hasMany(Ville::class);
    }
}
