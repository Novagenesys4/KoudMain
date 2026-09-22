<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ville extends Model
{
    public $timestamps = false;

    protected $fillable = ['nom'];

    public function departement(): BelongsTo
    {
        return $this->belongsTo(Departement::class);
    }

    public function quartiers(): HasMany
    {
        return $this->hasMany(Quartier::class);
    }
}
