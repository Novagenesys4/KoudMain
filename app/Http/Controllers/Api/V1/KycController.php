<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RefusApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\KycApiRequest;
use App\Http\Resources\Api\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification d'identité du prestataire (écran 20 du prototype).
 *   GET  /prestataire/kyc : où en est la demande ;
 *   POST /prestataire/kyc : envoi (services, zones, recto, verso, selfie).
 * Ouvert aux prestataires PAS ENCORE validés (c'est justement pour eux) ; numéro vérifié par SMS exigé.
 */
class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::succes($this->kyc->etat($this->prestataire($request)));
    }

    public function store(KycApiRequest $request): JsonResponse
    {
        $prestataire = $this->prestataire($request);
        $donnees = $request->validated();

        $this->kyc->soumettre(
            $prestataire,
            array_map('intval', $donnees['categories']),
            array_map('intval', $donnees['villes']),
            ['recto' => $request->file('recto'), 'verso' => $request->file('verso'), 'selfie' => $request->file('selfie')],
        );

        return ApiResponse::succes(
            ['kyc' => $this->kyc->etat($prestataire), 'user' => new UserResource($prestataire->refresh())],
            'Documents envoyés. Notre équipe vérifie votre profil sous 48 h ; vous recevrez un SMS.',
            201,
        );
    }

    private function prestataire(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        if (! $u->est_prestataire || $u->est_admin) {
            throw new RefusApi('Cette action est réservée aux prestataires.', 403, 'role_requis');
        }

        return $u;
    }
}
