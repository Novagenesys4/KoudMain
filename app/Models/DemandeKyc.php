<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une demande de vérification d'identité d'un prestataire (KYC). Table : demandes_kyc. */
class DemandeKyc extends Model
{
    public const EN_ATTENTE = 'en_attente';

    public const VALIDEE = 'validee';

    public const REFUSEE = 'refusee';

    /** Les trois photos exigées, dans l'ordre de l'écran. */
    public const DOCUMENTS = ['recto', 'verso', 'selfie'];

    protected $table = 'demandes_kyc';

    protected $fillable = ['statut', 'motif_refus'];

    protected function casts(): array
    {
        return ['traitee_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Categorie::class, 'demande_kyc_categorie', 'demande_kyc_id', 'categorie_id');
    }

    public function villes(): BelongsToMany
    {
        return $this->belongsToMany(Ville::class, 'demande_kyc_ville', 'demande_kyc_id', 'ville_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentKyc::class, 'demande_kyc_id');
    }
}
