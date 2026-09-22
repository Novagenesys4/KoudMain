<?php

/*
 * CORS : quels AUTRES sites ont le droit d'appeler KoudMain depuis le navigateur de leurs visiteurs.
 *
 * KoudMain est une application web classique : ses pages, ses formulaires et son JavaScript viennent tous du même site
 * (même origine), donc AUCUN autre site n'a besoin d'y accéder. Par défaut, aucun en-tête CORS n'est donc envoyé :
 * le navigateur refuse tout appel venu d'ailleurs.
 *
 * Si un jour une application mobile ou un site partenaire doit appeler une adresse précise, on liste ici, et seulement ici,
 * ses origines exactes (CORS_ALLOWED_ORIGINS="https://partenaire.example,https://app.example") et les chemins concernés
 * (CORS_PATHS="api/*"). Jamais de « * » : une origine générique est ignorée, même si on la saisit par erreur
 * (et « koudmain:controle-production » la signale).
 */

/** @var list<string> $origines */
$origines = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    fn (string $origine): bool => preg_match('#^https://[A-Za-z0-9.\-]+(:[0-9]{1,5})?$#', $origine) === 1
        || (env('APP_ENV', 'production') !== 'production' && preg_match('#^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origine) === 1),
));

return [

    // Sans origine autorisée, aucun chemin n'est ouvert : le middleware de CORS ne fait rien.
    'paths' => $origines === []
        ? []
        : array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_PATHS', 'api/*'))))),

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => $origines,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 600,

    // Pas de cookies de session envoyés à un autre site : un site tiers ne doit jamais agir « en tant que » l'utilisateur.
    'supports_credentials' => false,
];
