<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\RefusApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API, sur toutes les routes connectées : même filet de sécurité que le middleware « email.confirme » du site.
 * Un compte sans AUCUN identifiant vérifié (ni numéro, ni e-mail) n'utilise pas son jeton. Un numéro vérifié suffit :
 * l'adresse e-mail non confirmée n'empêche plus d'accéder à son espace (elle certifie seulement le compte).
 */
class CompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur !== null && config('koudmain.securite.confirmation_email') && ! $utilisateur->aUnIdentifiantVerifie()) {
            throw new RefusApi('Vérifiez votre numéro de téléphone ou votre adresse e-mail pour accéder à votre compte.', 403, 'compte_non_verifie');
        }

        return $next($request);
    }
}
