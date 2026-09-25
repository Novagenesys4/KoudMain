<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Le compte de la personne connectée (GET /auth/me). Jamais les rôles bruts en base : un « role » et un « statut_compte » lisibles. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $u */
        $u = $this->resource;
        $u->loadMissing(['quartier.ville', 'avatar']);

        return [
            'id' => $u->id,
            'prenom' => $u->prenom,
            'nom' => $u->nom,
            'nom_complet' => $u->nom_complet,
            'initiales' => mb_strtoupper(mb_substr((string) $u->prenom, 0, 1).mb_substr((string) $u->nom, 0, 1)),
            'email' => $u->email,
            'telephone' => $u->telephone,
            'telephone_verifie' => $u->telephoneVerifie(),
            'email_confirme' => $u->email_verified_at !== null,
            // Peut commander, agir sur une commande, recharger, retirer (sinon : écran de code). Voir User::compteVerifie.
            'compte_verifie' => $u->compteVerifie(),
            'role' => $u->espace(), // client | prestataire | admin : l'espace ouvert par défaut
            // Les espaces que l'application peut ouvrir (bascule « Passer en mode client / prestataire »).
            'espaces' => $u->espaces(),
            'statut_compte' => $u->enAttenteValidation() ? 'en_attente_validation' : 'actif',
            // Prestataires : a_fournir | en_attente | refusee | validee (null pour un client).
            'kyc' => $u->statutKyc(),
            'quartier' => $u->quartier ? [
                'id' => $u->quartier->id,
                'nom' => $u->quartier->nom,
                'ville' => $u->quartier->ville ? ['id' => $u->quartier->ville->id, 'nom' => $u->quartier->ville->nom] : null,
            ] : null,
            'avatar_url' => $u->avatar?->url(),
            'bio' => $u->bio,
            'notifications_email' => (bool) $u->notifications_email,
            'inscrit_le' => $u->created_at?->toIso8601String(),
        ];
    }
}
