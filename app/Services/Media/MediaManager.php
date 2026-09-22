<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Le seul point d'entrée pour les photos : envoi, remplacement, suppression, adresse publique.
 *
 * Trois temps, volontairement séparés :
 *  1. preparer()    : contrôle et nettoie l'image (peut refuser : ImageInvalide). Rien n'est encore écrit.
 *  2. enregistrer() : écrit le fichier sur le disque puis crée la ligne en base (et défait le fichier si la base refuse).
 *  3. supprimer()   : efface le fichier ET la ligne.
 * Ainsi un formulaire qui contient trois photos dont la deuxième est invalide n'enregistre rien du tout.
 */
class MediaManager
{
    /** @param array<string, StockageMedia> $stockages indexés par nom ("local", "supabase") */
    public function __construct(
        private readonly ImageProcessor $traitement,
        private readonly array $stockages,
        private readonly string $defaut,
    ) {
        if (! isset($stockages[$defaut])) {
            throw new InvalidArgumentException("Mode de stockage des photos inconnu : « $defaut » (attendu : local ou supabase).");
        }
    }

    /** @throws ImageInvalide */
    public function preparer(UploadedFile|string $fichier): ImageTraitee
    {
        $chemin = $fichier instanceof UploadedFile ? (string) $fichier->getRealPath() : $fichier;

        return $this->traitement->traiter($chemin);
    }

    /**
     * @throws \RuntimeException si le fichier ne peut pas être écrit
     */
    public function enregistrer(ImageTraitee $image, Model $proprietaire, string $type): Media
    {
        $dossier = match ($type) {
            Media::TYPE_PHOTO => 'prestations',
            Media::TYPE_AVATAR => 'avatars',
            default => throw new InvalidArgumentException("Type de média inconnu : « $type »."),
        };

        $stockage = $this->stockages[$this->defaut];
        $chemin = sprintf('%s/%d/%s.%s', $dossier, $proprietaire->getKey(), Str::lower((string) Str::ulid()), $image->extension);

        $stockage->ecrire($chemin, $image->binaire, $image->mime);

        try {
            $position = $type === Media::TYPE_PHOTO
                ? 1 + (int) Media::query()
                    ->where('mediable_type', $proprietaire->getMorphClass())
                    ->where('mediable_id', $proprietaire->getKey())
                    ->where('type', $type)
                    ->max('position')
                : 0;

            $media = new Media([
                'type' => $type,
                'disk' => $stockage->nom(),
                'chemin' => $chemin,
                'mime' => $image->mime,
                'taille_octets' => $image->taille(),
                'largeur' => $image->largeur,
                'hauteur' => $image->hauteur,
                'position' => $position,
            ]);
            $media->mediable()->associate($proprietaire);
            $media->save();

            return $media;
        } catch (Throwable $e) {
            $stockage->supprimer($chemin); // pas de fichier orphelin si la base a refusé
            throw $e;
        }
    }

    /** @throws ImageInvalide */
    public function ajouter(UploadedFile $fichier, Model $proprietaire, string $type): Media
    {
        return $this->enregistrer($this->preparer($fichier), $proprietaire, $type);
    }

    /**
     * Remplace l'avatar. Le tout est une transaction : si l'enregistrement du nouveau échoue, l'ancien reste en place.
     * Le fichier de l'ancien n'est effacé qu'une fois la transaction validée.
     */
    public function remplacerAvatar(ImageTraitee $image, Model $utilisateur): Media
    {
        [$nouveau, $anciens] = DB::transaction(function () use ($image, $utilisateur): array {
            $anciens = Media::query()
                ->where('mediable_type', $utilisateur->getMorphClass())
                ->where('mediable_id', $utilisateur->getKey())
                ->where('type', Media::TYPE_AVATAR)
                ->get();

            // L'index unique partiel (un seul avatar par utilisateur) impose de retirer l'ancienne ligne avant d'insérer.
            foreach ($anciens as $ancien) {
                $ancien->delete();
            }

            return [$this->enregistrer($image, $utilisateur, Media::TYPE_AVATAR), $anciens];
        });

        $this->supprimerFichiers($anciens);

        return $nouveau;
    }

    public function supprimer(Media $media): void
    {
        $media->delete();
        $this->supprimerFichiers([$media]);
    }

    /** Efface les fichiers (pas les lignes) : les fichiers de plusieurs médias, groupés par disque. */
    public function supprimerFichiers(iterable $medias): void
    {
        $parDisque = [];

        foreach ($medias as $media) {
            $parDisque[$media->disk][] = $media->chemin;
        }

        foreach ($parDisque as $disque => $chemins) {
            ($this->stockages[$disque] ?? null)?->supprimer(...$chemins);
        }
    }

    public function url(Media $media): string
    {
        $stockage = $this->stockages[$media->disk] ?? throw new InvalidArgumentException("Disque inconnu pour la photo {$media->id} : « {$media->disk} ».");

        return $stockage->url($media->chemin);
    }
}
