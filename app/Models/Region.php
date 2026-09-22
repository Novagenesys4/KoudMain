<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    public $timestamps = false; // tables de référence : pas de created_at / updated_at

    protected $fillable = ['nom'];

    public function departements(): HasMany
    {
        return $this->hasMany(Departement::class);
    }
}
