<?php

use App\Http\Middleware\Api\CompteActif;
use App\Http\Middleware\Api\ExigerRole;
use App\Http\Middleware\Api\TelephoneVerifie;
use App\Http\Middleware\ContexteRequete;
use App\Http\Responses\ApiExceptionRenderer;
use App\Http\Middleware\EmailConfirme;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Surveillance\Sentry;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API de l'application mobile (Flutter) : /api/v1/..., authentifiée par jetons Sanctum. Voir routes/api.php.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sur Render, le proxy termine le HTTPS : on lui fait confiance pour connaître le vrai schéma
        // (https) et le vrai hôte. L'IP du visiteur, elle, est lue par App\Support\ClientIp.
        if (filter_var(env('TRUST_PROXY', false), FILTER_VALIDATE_BOOL)) {
            $middleware->trustProxies(
                at: '*',
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        // HTTPS partout (règle 8) : en production, tout ce qui arrive en HTTP est redirigé avant même de lire un cookie.
        // append (et non prepend) : ForceHttps doit passer APRÈS TrustProxies, sinon X-Forwarded-Proto n'est pas encore lu,
        // chaque requête semble « http » et le site redirige en boucle derrière le proxy de Render. Les cookies et la session
        // ne sont lus que dans le groupe « web », donc toujours après ce middleware global.
        $middleware->append(ForceHttps::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'email.confirme' => EmailConfirme::class,
            // API mobile : rôle exigé, compte actif (adresse confirmée), numéro de téléphone vérifié par SMS.
            'api.role' => ExigerRole::class,
            'api.compte' => CompteActif::class,
            'api.telephone' => TelephoneVerifie::class,
        ]);

        // Groupe « api » : pas de session ni de cookie (jetons uniquement), mais les mêmes en-têtes de sécurité, le même numéro de
        // requête dans les journaux, et un plafond de requêtes par utilisateur (ou par IP pour un visiteur) : RateLimiter « api ».
        $middleware->api(prepend: [SecurityHeaders::class], append: [
            ThrottleRequests::class.':api',
            ContexteRequete::class,
        ]);

        // Cookie posé par le navigateur (écran d'ouverture déjà vu) : il ne contient rien de secret, il n'est donc pas chiffré.
        $middleware->encryptCookies(except: ['km_intro']);

        $middleware->web(append: [
            // En premier : ainsi même une réponse « trop de requêtes » (429) ou d'erreur porte les en-têtes de sécurité.
            SecurityHeaders::class,
            // Sessions (règle 9) : changer de mot de passe met fin aux sessions ouvertes ailleurs (Auth::logoutOtherDevices).
            AuthenticateSession::class,
            // Plafond général de requêtes par utilisateur (ou par IP pour un visiteur) : voir RateLimiter « web » (AppServiceProvider).
            ThrottleRequests::class.':web',
            ContexteRequete::class,
        ]);

        // Laravel range certains middlewares (dont ThrottleRequests) dans un ordre fixe : SecurityHeaders y est placé AVANT le plafond de requêtes,
        // sinon une réponse 429 partirait sans en-têtes de sécurité.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: SecurityHeaders::class);

        // Adresses appelées par l'agrégateur de paiement (le navigateur du client ou son serveur) : elles n'ont pas
        // de jeton CSRF. Elles ne font confiance à rien de ce qu'elles reçoivent (voir PaiementRetourController).
        $middleware->validateCsrfTokens(except: ['paiements/retour', 'paiements/notification']);

        // Visiteur non connecté -> page de connexion ; utilisateur déjà connecté sur /connexion -> son espace.
        $middleware->redirectGuestsTo(fn () => route('connexion'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->routeTableauDeBord()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un numéro de carte ou un code de sécurité n'est jamais rejoué dans un formulaire après une erreur (il finirait dans la session).
        $exceptions->dontFlash(['numero_carte', 'cvv']);

        // API mobile : toute erreur sous /api/* répond en JSON standard { success: false, message, data, code, errors? }
        // (jamais une page HTML ni une redirection vers /connexion). Le signalement (journal, Sentry) n'est pas modifié.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (\Throwable $e, Request $request) => $request->is('api/*') ? ApiExceptionRenderer::rendre($e) : null);

        // Toute erreur inattendue (pas les 404, validations, accès refusés... que Laravel ne « rapporte » pas) part aussi vers
        // Sentry quand SENTRY_DSN est renseigné. Elle reste écrite dans le journal comme avant.
        $exceptions->report(function (\Throwable $e): void {
            app(Sentry::class)->capturer($e);
        });
    })->create();
