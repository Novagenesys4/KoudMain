<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité HTTP (ex-forcerHttpsEtHeaders() de securite.php).
 *
 * La Content-Security-Policy n'est pas envoyée en local : le serveur de développement Vite
 * (localhost:5173) serait bloqué. HSTS n'est envoyé qu'en production et en HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (! app()->environment('local')) {
            $response->headers->set('Content-Security-Policy', $this->politique());
        }

        // HSTS (règle 8) : une fois qu'il a vu le site en HTTPS, le navigateur refuse de revenir en HTTP pendant un an.
        // Envoyé sur TOUTES les réponses HTTPS de production, redirections et erreurs comprises.
        if (app()->isProduction() && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('koudmain.securite.hsts_secondes').'; includeSubDomains');
        }

        return $response;
    }

    /**
     * 'unsafe-inline' est nécessaire tant que des scripts et styles restent en ligne (thème appliqué
     * avant l'affichage, styles posés par les îlots React). À resserrer avec des "nonces" plus tard.
     * Les polices sont servies par le site lui-même (paquets @fontsource) : plus aucun hôte externe.
     */
    private function politique(): string
    {
        $images = trim("'self' data: blob: ".implode(' ', config('koudmain.csp_img_hosts', [])));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src $images",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
