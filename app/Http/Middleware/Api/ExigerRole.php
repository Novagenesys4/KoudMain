<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\RefusApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API : exige un rôle (« api.role:client », « api.role:prestataire », ou les deux « api.role:client,prestataire »).
 * Même règle que le site (User::aLeRole), mais une réponse JSON explicite : l'application sait quoi afficher.
 */
class ExigerRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $utilisateur = $request->user();

        foreach ($roles as $role) {
            if ($utilisateur?->aLeRole($role)) {
                return $next($request);
            }
        }

        if (in_array('prestataire', $roles, true) && $utilisateur?->enAttenteValidation()) {
            throw new RefusApi('Votre profil prestataire est en cours de vérification par l\'équipe KoudMain. Vous recevrez un SMS dès qu\'il sera validé.', 403, 'prestataire_non_valide');
        }

        $pour = match ($roles) {
            ['client'] => 'aux clients',
            ['prestataire'] => 'aux prestataires',
            default => 'aux clients et aux prestataires',
        };

        throw new RefusApi("Cette action est réservée $pour.", 403, 'role_requis');
    }
}
