<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Prestation;
use App\Models\User;
use App\Services\Media\ImageInvalide;
use App\Services\Media\ImageTraitee;
use App\Services\Media\MediaManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tout ce qu'un prestataire fait de ses prestations : créer, modifier, photos, masquer, supprimer
 * (reprend l'ancien prestation_service.php). Les contrôleurs ne contiennent que du HTTP.
 */
class PrestationService
{
    public function __construct(private readonly MediaManager $medias)
    {
    }

    /**
     * @param  array<string, mixed>  $donnees  titre, service_id, prix, duree_minutes, description (déjà validés)
     * @param  list<UploadedFile>  $photos
     *
     * @throws ImageInvalide si une photo est refusée : dans ce cas, RIEN n'est enregistré
     */
    public function creer(User $prestataire, array $donnees, array $photos = []): Prestation
    {
        $images = $this->preparer($photos, 0);

        $prestation = $this->avecPhotos($images, fn () => $prestataire->prestations()->create($donnees));

        Log::info('prestation.creee', ['prestation' => $prestation->id, 'prestataire' => $prestataire->id, 'photos' => count($images)]);

        return $prestation;
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @param  list<UploadedFile>  $photos
     *
     * @throws ImageInvalide
     */
    public function mettreAJour(Prestation $prestation, array $donnees, array $photos = []): Prestation
    {
        $images = $this->preparer($photos, $this->nombrePhotos($prestation));

        $this->avecPhotos($images, function () use ($prestation, $donnees): Prestation {
            $prestation->update($donnees);

            return $prestation;
        });

        Log::info('prestation.modifiee', ['prestation' => $prestation->id, 'photos_ajoutees' => count($images)]);

        return $prestation;
    }

    /**
     * @param  list<UploadedFile>  $photos
     *
     * @throws ImageInvalide
     */
    public function ajouterPhotos(Prestation $prestation, array $photos): int
    {
        $images = $this->preparer($photos, $this->nombrePhotos($prestation));

        $this->avecPhotos($images, fn () => $prestation);

        return count($images);
    }

    public function retirerPhoto(Media $photo): void
    {
        $this->medias->supprimer($photo);
    }

    /** Place cette photo en premier : c'est celle qui illustre la carte dans le catalogue. */
    public function definirPhotoPrincipale(Prestation $prestation, Media $photo): void
    {
        DB::transaction(function () use ($prestation, $photo): void {
            $ids = $prestation->medias()->pluck('id')->all();
            $ordre = [$photo->id, ...array_values(array_diff($ids, [$photo->id]))];

            foreach ($ordre as $rang => $id) {
                Media::query()->whereKey($id)->update(['position' => $rang + 1]);  // les positions commencent à 1, comme à l'ajout
            }
        });
    }

    public function basculerActivation(Prestation $prestation): Prestation
    {
        $prestation->update(['est_active' => ! $prestation->est_active]);

        Log::info($prestation->est_active ? 'prestation.publiee' : 'prestation.masquee', ['prestation' => $prestation->id]);

        return $prestation;
    }

    /**
     * Supprime une prestation JAMAIS commandée. Une prestation déjà commandée est conservée : sa suppression
     * effacerait l'historique des commandes et les avis qui en dépendent (la base l'interdit d'ailleurs).
     *
     * @return bool false si la prestation a déjà été commandée (le prestataire doit la masquer)
     */
    public function supprimer(Prestation $prestation): bool
    {
        if ($prestation->aDejaEteCommandee()) {
            return false;
        }

        try {
            $prestation->delete();
        } catch (QueryException $e) {
            // Une commande vient d'être passée entre-temps : la clé étrangère de la base protège l'historique.
            Log::info('prestation.suppression_refusee', ['prestation' => $prestation->id]);

            return false;
        }

        Log::info('prestation.supprimee', ['prestation' => $prestation->id]);

        return true;
    }

    /** Combien de photos peut-on encore ajouter ? */
    public function placesRestantes(Prestation $prestation): int
    {
        return max(0, (int) config('koudmain.media.photos_par_prestation') - $this->nombrePhotos($prestation));
    }

    private function nombrePhotos(Prestation $prestation): int
    {
        return $prestation->exists ? $prestation->medias()->count() : 0;
    }

    /**
     * Contrôle et nettoie toutes les images AVANT d'écrire quoi que ce soit.
     *
     * @param  list<UploadedFile>  $fichiers
     * @return list<ImageTraitee>
     *
     * @throws ImageInvalide
     */
    private function preparer(array $fichiers, int $dejaLa): array
    {
        $max = (int) config('koudmain.media.photos_par_prestation');

        if ($dejaLa + count($fichiers) > $max) {
            $reste = max(0, $max - $dejaLa);

            throw new ImageInvalide($reste === 0
                ? "Cette prestation a déjà $max photos (le maximum). Supprimez-en une avant d'en ajouter."
                : "Vous pouvez encore ajouter $reste photo".($reste > 1 ? 's' : '').' à cette prestation.');
        }

        $images = [];

        foreach ($fichiers as $fichier) {
            try {
                $images[] = $this->medias->preparer($fichier);
            } catch (ImageInvalide $e) {
                $nom = $fichier->getClientOriginalName();

                throw new ImageInvalide("« $nom » : ".$e->getMessage(), 0, $e);
            }
        }

        return $images;
    }

    /**
     * Enregistre la prestation puis ses photos dans UNE transaction. Si quelque chose échoue, la base revient en arrière
     * et les fichiers déjà écrits sont effacés : pas de prestation à moitié créée, pas de fichier orphelin.
     *
     * @param  list<ImageTraitee>  $images
     * @param  callable(): Prestation  $ecrire  crée ou modifie la prestation
     */
    private function avecPhotos(array $images, callable $ecrire): Prestation
    {
        $ecrites = [];

        try {
            return DB::transaction(function () use ($images, $ecrire, &$ecrites): Prestation {
                $prestation = $ecrire();

                foreach ($images as $image) {
                    $ecrites[] = $this->medias->enregistrer($image, $prestation, Media::TYPE_PHOTO);
                }

                return $prestation;
            });
        } catch (Throwable $e) {
            $this->medias->supprimerFichiers($ecrites);

            throw $e;
        }
    }
}
