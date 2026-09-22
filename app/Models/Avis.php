<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis d'un client sur une prestation d'une commande terminée.
 * Table : avis (invariable en français, mais Laravel la chercherait au pluriel anglais).
 * Le dépôt d'un avis arrive au lot 4 ; ce modèle sert déjà à AFFICHER les notes dans le catalogue.
 */
class Avis extends Model
{
    protected $table = 'avis';

    protected $fillable = ['note', 'commentaire'];

    protected function casts(): array
    {
        return [
            'note' => 'integer',
            'modifie_at' => 'datetime',
        ];
    }

    public function prestation(): BelongsTo
    {
        return $this->belongsTo(Prestation::class);
    }

    /** L'auteur (le client). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }
}
