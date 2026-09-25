<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
 * Sanctum sert UNIQUEMENT à l'application mobile, par jetons (en-tête « Authorization: Bearer ... »).
 *
 *  - stateful vide et guard vide : l'API n'accepte jamais la session du site web (cookie). Un jeton est le seul moyen
 *    d'appeler /api/v1. Le site web garde sa propre authentification par session, inchangée ;
 *  - expiration : un jeton expire après koudmain.api.jeton_jours (60 jours par défaut) ; l'application se reconnecte ;
 *  - token_prefix : « km_ » permet aux scanners de secrets (GitHub, gitleaks) de reconnaître un jeton KoudMain collé par erreur.
 */
return [
    'stateful' => [],

    'guard' => [],

    'expiration' => (int) env('API_JETON_JOURS', 60) * 24 * 60,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'km_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
