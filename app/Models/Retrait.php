<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une demande de retrait d'un prestataire. Ne change que via WalletService. */
class Retrait extends Model
{
    public const EN_ATTENTE = 'en_attente';
    public const EFFECTUE = 'effectue';
    public const REFUSE = 'refuse';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'traite_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
