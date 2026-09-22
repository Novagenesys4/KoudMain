<?php

namespace App\Http\Controllers;

use App\Http\Requests\Compte\AvatarRequest;
use App\Http\Requests\Compte\IdentiteRequest;
use App\Http\Requests\Compte\ProfilRequest;
use App\Models\Media;
use App\Services\Media\ImageInvalide;
use App\Services\Media\MediaManager;
use App\Support\Journal;
use App\Support\Referentiel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /compte/profil : informations personnelles et photo (tous), présentation publique (prestataires). */
class ProfilController extends Controller
{
    public function __construct(private readonly MediaManager $medias)
    {
    }

    public function edit(Request $request): View
    {
        return view('compte.profil', [
            'user' => $request->user()->load('avatar', 'quartier.ville'),
            'villes' => Referentiel::villes(),
        ]);
    }

    /** Prénom, nom, téléphone et quartier. Ouvert à tous les rôles. */
    public function modifierIdentite(IdentiteRequest $request): RedirectResponse
    {
        $user = $request->user();
        $donnees = $request->validated();

        $user->fill($donnees)->save();

        Journal::info('profil.identite', ['utilisateur' => $user->id, 'champs' => array_keys($user->getChanges())]);

        return back()->with('succes', 'Vos informations sont enregistrées.');
    }

    public function update(ProfilRequest $request): RedirectResponse
    {
        abort_unless($request->user()->est_prestataire, 403);

        $request->user()->update(['bio' => $request->validated('bio')]);

        return back()->with('succes', 'Présentation enregistrée. Elle est visible sur votre profil public.');
    }

    /** Recevoir (ou non) les e-mails de notification. La cloche, dans le site, reste toujours active. */
    public function preferencesNotifications(Request $request): RedirectResponse
    {
        $donnees = $request->validate(['notifications_email' => ['required', 'boolean']]);

        $request->user()->forceFill(['notifications_email' => (bool) $donnees['notifications_email']])->save();

        return back()->with('succes', $donnees['notifications_email'] ? 'Vous recevrez les e-mails de notification.' : 'Les e-mails de notification sont coupés. Vous restez prévenu dans KoudMain.');
    }

    public function envoyerAvatar(AvatarRequest $request): RedirectResponse
    {
        try {
            $image = $this->medias->preparer($request->file('avatar'));
        } catch (ImageInvalide $e) {
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        $this->medias->remplacerAvatar($image, $request->user());

        return back()->with('succes', 'Photo de profil mise à jour.');
    }

    public function supprimerAvatar(Request $request): RedirectResponse
    {
        $avatar = $request->user()->avatar()->first();

        if ($avatar instanceof Media) {
            $this->medias->supprimer($avatar);
        }

        return back()->with('succes', 'Photo de profil supprimée.');
    }
}
