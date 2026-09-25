<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filet de sécurité (règle 19) : un compte sans AUCUN identifiant vérifié (ni e-mail confirmé, ni numéro vérifié par SMS dans
 * l'application) n'accède jamais à un espace. Un compte créé sur l'application (numéro vérifié) entre donc sur le site même si son
 * adresse e-mail n'est pas encore confirmée.
 *
 * La connexion refuse déjà ces comptes (voir ConnexionRequest) ; ce middleware couvre le reste : une session ou un cookie
 * « rester connecté » créé avant l'obligation de confirmer, une bascule manuelle en base... Il s'applique à toutes les pages
 * réservées aux utilisateurs connectés. La personne est déconnectée et renvoyée vers la connexion.
 */
class EmailConfirme
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (config('koudmain.securite.confirmation_email') && $utilisateur !== null && ! $utilisateur->aUnIdentifiantVerifie()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('connexion')->with('erreur', 'Confirmez votre adresse e-mail pour accéder à votre compte : ouvrez le message que nous vous avons envoyé.');
        }

        return $next($request);
    }
}
