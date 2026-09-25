<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une demande de code SMS (API mobile). Rien n'est remplissable en masse : seul App\Services\OtpService la crée et la fait évoluer.
 * Le code n'est jamais stocké en clair (code_hash) ; l'identifiant est un UUID aléatoire.
 */
class VerificationOtp extends Model
{
    use HasUuids;

    public const INSCRIPTION = 'inscription';

    public const TELEPHONE = 'telephone';

    public const MOT_DE_PASSE = 'mot_de_passe';

    protected $table = 'verifications_otp';

    protected $fillable = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'tentatives' => 'integer',
            'envois' => 'integer',
            'envoye_at' => 'datetime',
            'expire_at' => 'datetime',
            'utilise_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estUtilisable(): bool
    {
        return $this->utilise_at === null && $this->expire_at->isFuture();
    }
}
