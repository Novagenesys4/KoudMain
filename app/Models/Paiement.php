<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une tentative de recharge du wallet par Mobile Money. Ne change que via PaiementService. */
class Paiement extends Model
{
    public const EN_ATTENTE = 'en_attente';
    public const REUSSI = 'reussi';
    public const ECHOUE = 'echoue';
    public const ANNULE = 'annule';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'reussi_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
