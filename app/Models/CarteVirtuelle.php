<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarteVirtuelle extends Model
{
    /**
     * Laravel déduit les noms de table en pluralisant à l'ANGLAIS : "CarteVirtuelle"
     * donnerait "carte_virtuelles". Pour un nom composé français, on indique la table.
     */
    protected $table = 'cartes_virtuelles';

    protected $fillable = [
        'libelle',
        'type_carte',
        'couleur',
        'numero_masque',
        'nom_titulaire',
        'date_expiration',
        'adresse_facturation',
    ]; // wallet_id, empreinte, est_principale et est_gelee ne se remplissent jamais depuis un formulaire : le service les fixe

    /** Jamais montrée : l'empreinte ne quitte pas le serveur. */
    protected $hidden = ['empreinte'];

    protected function casts(): array
    {
        return [
            'est_principale' => 'boolean',
            'est_gelee' => 'boolean',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'carte_id');
    }
}
