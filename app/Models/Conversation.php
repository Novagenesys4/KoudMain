<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La discussion d'une commande, entre son client et son prestataire (une seule par commande).
 * Elle se crée au premier message ; MessageService s'en charge, jamais un formulaire.
 */
class Conversation extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['dernier_message_at' => 'datetime'];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
