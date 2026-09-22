<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    /** Registre immuable : seule la date de création existe. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'montant',
        'libelle',
        'solde_apres',
        'commande_id',
        'carte_id',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function carte(): BelongsTo
    {
        return $this->belongsTo(CarteVirtuelle::class, 'carte_id');
    }
}
