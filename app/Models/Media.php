<?php

namespace App\Models;

use App\Services\Media\MediaManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une image (photo de prestation, avatar). Le fichier vit sur un « disque » (dossier local ou Supabase Storage) ;
 * la base ne garde que son emplacement. Table : medias (le pluriel anglais de « media » serait « media »).
 */
class Media extends Model
{
    public const TYPE_PHOTO = 'photo_prestation';

    public const TYPE_AVATAR = 'avatar';

    protected $table = 'medias';

    protected $fillable = [
        'type',
        'disk',
        'chemin',
        'mime',
        'taille_octets',
        'largeur',
        'hauteur',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'largeur' => 'integer',
            'hauteur' => 'integer',
            'position' => 'integer',
        ];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Adresse publique de l'image. */
    public function url(): string
    {
        return app(MediaManager::class)->url($this);
    }
}
