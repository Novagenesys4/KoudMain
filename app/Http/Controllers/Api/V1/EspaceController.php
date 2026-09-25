<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RefusApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Double rôle (Phase 4 de l'application mobile) : « Passer en mode prestataire / client » du Profil.
 *
 *   POST /auth/espaces/prestataire : un client ouvre AUSSI un espace prestataire. Le profil prestataire n'est pas
 *        validé (est_valide = false) : l'application enchaîne sur la vérification d'identité (KYC), puis l'équipe valide.
 *        L'espace client reste ouvert pendant ce temps (User::espace, ConnexionRequest).
 *   POST /auth/espaces/client : un prestataire ouvre AUSSI un espace client (réserver des services).
 *
 * Les deux sont idempotents. Aucun droit n'est donné ici au-delà du rôle demandé : un prestataire reste soumis à la
 * validation, et « commander sa propre prestation » est refusé par CommandeService.
 * Réponse : le compte à jour (UserResource), dont « espaces ».
 */
class EspaceController extends Controller
{
    public function prestataire(Request $request): JsonResponse
    {
        $moi = $this->compte($request);

        return ApiResponse::succes(new UserResource($moi->refresh()), $this->ouvrirPrestataire($moi));
    }

    public function client(Request $request): JsonResponse
    {
        $moi = $this->compte($request);

        return ApiResponse::succes(new UserResource($moi->refresh()), $this->ouvrirClient($moi));
    }

    private function compte(Request $request): User
    {
        /** @var User $moi */
        $moi = $request->user();

        if ($moi->est_admin) {
            throw new RefusApi('Un compte administrateur ne change pas d\'espace dans l\'application.', 403, 'role_requis');
        }

        return $moi;
    }

    private function ouvrirPrestataire(User $moi): string
    {
        if ($moi->est_prestataire) {
            return 'Votre espace prestataire est déjà ouvert.';
        }

        $moi->forceFill(['est_prestataire' => true, 'est_valide' => false])->save();
        Journal::info('espace.prestataire_ouvert', ['utilisateur' => $moi->id]);

        return 'Espace prestataire ouvert. Dernière étape : vérifiez votre identité pour recevoir des commandes.';
    }

    private function ouvrirClient(User $moi): string
    {
        if ($moi->est_client) {
            return 'Votre espace client est déjà ouvert.';
        }

        $moi->forceFill(['est_client' => true])->save();
        Journal::info('espace.client_ouvert', ['utilisateur' => $moi->id]);

        return 'Espace client ouvert : vous pouvez maintenant réserver des services.';
    }
}
