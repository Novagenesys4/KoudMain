<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le séquestre d'une commande : l'argent du client, mis de côté jusqu'à la fin de la prestation.
 * Rien n'est remplissable en masse : seul CommandeService le crée et le fait évoluer.
 */
class Escrow extends Model
{
    public const BLOQUE = 'bloque';
    public const LIBERE = 'libere';
    public const REMBOURSE = 'rembourse';
    public const LITIGE = 'litige';

    public const CREATED_AT = 'bloque_at';
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'bloque_at' => 'datetime',
            'libere_at' => 'datetime',
            'rembourse_at' => 'datetime',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
