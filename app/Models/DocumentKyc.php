<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une photo de vérification (recto, verso, selfie). Le fichier est chiffré ; voir App\Services\Kyc\KycService. */
class DocumentKyc extends Model
{
    protected $table = 'documents_kyc';

    protected $fillable = ['type', 'disk', 'chemin', 'mime', 'taille_octets', 'empreinte'];

    protected function casts(): array
    {
        return ['taille_octets' => 'integer'];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeKyc::class, 'demande_kyc_id');
    }
}
