<?php

namespace App\Http\Controllers\Prestataire;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prestation\PhotosRequest;
use App\Models\Media;
use App\Models\Prestation;
use App\Services\Media\ImageInvalide;
use App\Services\PrestationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Photos d'une prestation : ajouter, retirer, choisir la principale. */
class PrestationPhotoController extends Controller
{
    public function __construct(private readonly PrestationService $prestations)
    {
    }

    public function store(PhotosRequest $request, Prestation $prestation): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);

        try {
            $nombre = $this->prestations->ajouterPhotos($prestation, $request->photos());
        } catch (ImageInvalide $e) {
            return back()->withErrors(['photos' => $e->getMessage()]);
        }

        return back()->with('succes', $nombre > 1 ? "$nombre photos ajoutées." : 'Photo ajoutée.');
    }

    public function destroy(Prestation $prestation, Media $media): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);
        $this->verifierAppartenance($prestation, $media);

        $this->prestations->retirerPhoto($media);

        return back()->with('succes', 'Photo supprimée.');
    }

    public function principale(Prestation $prestation, Media $media): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);
        $this->verifierAppartenance($prestation, $media);

        $this->prestations->definirPhotoPrincipale($prestation, $media);

        return back()->with('succes', 'Photo principale mise à jour : c\'est elle qui illustre votre prestation dans le catalogue.');
    }

    /** Une photo d'un autre prestataire (ou d'un autre type) : 404, comme si elle n'existait pas. */
    private function verifierAppartenance(Prestation $prestation, Media $media): void
    {
        abort_unless(
            $media->type === Media::TYPE_PHOTO
            && $media->mediable_type === $prestation->getMorphClass()
            && (int) $media->mediable_id === $prestation->id,
            404,
        );
    }
}
