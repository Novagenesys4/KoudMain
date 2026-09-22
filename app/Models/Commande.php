<?php

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\StatutCommande;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Commande extends Model
{
    /**
     * Ni le statut, ni les dates de transition, ni le prestataire ne sont remplissables en masse :
     * ils ne changent que via le service de workflow (lot 3), jamais depuis une valeur
     * envoyée par le navigateur.
     */
    protected $fillable = [
        'client_id',
        'quartier_id',
        'montant_total',
        'date_souhaitee',
        'duree_minutes',
        'precisions',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCommande::class,
            'mode_paiement' => ModePaiement::class,
            'montant_total' => 'decimal:2',
            'acceptee_at' => 'datetime',
            'debut_at' => 'datetime',
            'terminee_at' => 'datetime',
            'annulee_at' => 'datetime',
            'validee_client_at' => 'datetime',
            'date_souhaitee' => 'datetime',
            'duree_minutes' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /** Toujours déduit côté serveur (prestation -> prestataire), jamais accepté depuis le navigateur. */
    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prestataire_id');
    }

    public function quartier(): BelongsTo
    {
        return $this->belongsTo(Quartier::class);
    }

    /** Le séquestre de la commande (une commande = au plus un séquestre). */
    public function escrow(): HasOne
    {
        return $this->hasOne(Escrow::class);
    }

    /** Réglée en main propre : rien ne passe par le wallet, il n'y a donc pas de séquestre. */
    public function payeeEnPhysique(): bool
    {
        return $this->mode_paiement === ModePaiement::Physique;
    }

    public function avis(): HasMany
    {
        return $this->hasMany(Avis::class);
    }

    public function prestations(): BelongsToMany
    {
        return $this->belongsToMany(Prestation::class, 'commande_prestation')
            ->withPivot(['prix_unitaire', 'quantite']);
    }
}
