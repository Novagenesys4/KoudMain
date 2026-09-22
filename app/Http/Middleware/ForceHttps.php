<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTPS partout (règle 8) : en production, une requête arrivée en HTTP est renvoyée vers la même adresse en HTTPS (301) avant
 * toute autre chose, donc avant qu'un cookie ou un formulaire ne circule en clair. Le navigateur retient ensuite l'obligation
 * grâce à l'en-tête HSTS (voir SecurityHeaders).
 *
 * Deux garde-fous :
 *  - /up (contrôle de santé de Render et de Docker) reste joignable en HTTP : le contrôleur de santé n'a pas de certificat ;
 *  - la redirection ne s'active que si l'application sait lire le schéma d'origine derrière le proxy (TRUST_PROXY=true), sinon
 *    elle croirait chaque requête « non sécurisée » et redirigerait sans fin (voir config koudmain.securite.forcer_https).
 *
 * L'adresse de destination vient d'APP_URL (jamais de l'en-tête Host, que le visiteur peut falsifier : sinon, redirection ouverte).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('koudmain.securite.forcer_https') || $request->secure() || $request->is('up')) {
            return $next($request);
        }

        $hote = parse_url((string) config('app.url'), PHP_URL_HOST);

        // APP_URL absente ou illisible : on garde l'hôte reçu plutôt que de casser le site (le contrôle de production le signale).
        $hote = is_string($hote) && $hote !== '' ? $hote : $request->getHost();

        return redirect()->to('https://'.$hote.$request->getRequestUri(), 301);
    }
}
