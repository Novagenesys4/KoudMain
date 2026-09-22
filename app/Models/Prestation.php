<?php

namespace App\Models;

use App\Services\Media\MediaManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Prestation extends Model
{
    use HasFactory;

    /**
     * prestataire_id n'est pas remplissable : on crée toujours via la relation,
     * $user->prestations()->create([...]), qui le renseigne toute seule
     * (le prestataire n'est jamais lu depuis le navigateur).
     * Les photos ne sont plus une colonne : elles sont dans la table "medias" (lot 2).
     */
    protected $fillable = [
        'service_id',
        'titre',
        'description',
        'prix',
        'duree_minutes',
        'est_active',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'duree_minutes' => 'integer',
            'est_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Le slug est créé une fois, à la création, et ne change plus (les liens restent valables).
        static::creating(function (Prestation $prestation): void {
            $prestation->slug ??= self::genererSlug((string) $prestation->titre);
        });

        // Les photos sont liées par une relation « polymorphe » : la base ne les supprime pas toute seule.
        // On efface donc les fichiers ET les lignes ici (requêtes explicites : pas de chargement paresseux).
        static::deleting(function (Prestation $prestation): void {
            $photos = $prestation->medias()->get();
            $prestation->medias()->delete();
            app(MediaManager::class)->supprimerFichiers($photos);
        });
    }

    /** "Coiffure tresses" -> "coiffure-tresses-k3x9ab" (suffixe aléatoire : unique sans compter les lignes). */
    public static function genererSlug(string $titre): string
    {
        $base = Str::limit(Str::slug($titre), 150, '') ?: 'prestation';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::query()->where('slug', $slug)->exists());

        return $slug;
    }

    /** Les URL utilisent le slug : /prestations/coiffure-tresses-k3x9ab. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prestataire_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Photos, dans l'ordre : la première est la photo principale. */
    public function medias(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')
            ->where('type', Media::TYPE_PHOTO)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function avis(): HasMany
    {
        return $this->hasMany(Avis::class);
    }

    /**
     * Ce que voit le public : prestation active d'un prestataire validé.
     * (Un prestataire suspendu ou non encore validé ne doit jamais apparaître dans le catalogue.)
     */
    public function scopeVisibles(Builder $requete): Builder
    {
        return $requete
            ->where('prestations.est_active', true)
            ->whereHas('prestataire', fn (Builder $q) => $q->where('est_prestataire', true)->where('est_valide', true));
    }

    /** Vrai si cette prestation a déjà été commandée (elle ne peut alors plus être supprimée, seulement masquée). */
    public function aDejaEteCommandee(): bool
    {
        return $this->commandes()->exists();
    }

    public function commandes(): BelongsToMany
    {
        return $this->belongsToMany(Commande::class, 'commande_prestation')
            ->withPivot(['prix_unitaire', 'quantite']);
    }
}
