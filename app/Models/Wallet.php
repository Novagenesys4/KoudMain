<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /**
     * Rien n'est remplissable en masse : le solde ne bouge que par le service Wallet
     * (étape 7), dans une transaction avec verrou de ligne. Création : $user->wallet()->create().
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'solde' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function cartes(): HasMany
    {
        return $this->hasMany(CarteVirtuelle::class);
    }
}
