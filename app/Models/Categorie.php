<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categorie extends Model
{
    public $timestamps = false;

    protected $fillable = ['nom'];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
