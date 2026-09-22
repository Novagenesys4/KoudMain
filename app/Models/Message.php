<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un message. Il ne se modifie jamais (pas de updated_at) ; seul le « lu » change, par MessageService.
 */
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['lu' => 'boolean', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
    }
}
