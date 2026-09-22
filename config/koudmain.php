<?php

/*
 * Réglages propres à KoudMain. Toute valeur qui varie selon l'environnement (local, Render...)
 * passe par une variable d'environnement (.env en local, "Environment" sur Render).
 */
return [

    /*
     * Derrière Render (reverse-proxy), l'IP de la connexion est celle du proxy. Avec TRUST_PROXY=true,
     * on lit la DERNIÈRE adresse de X-Forwarded-For : celle écrite par le proxy de confiance,
     * que le client ne peut pas falsifier (voir App\Support\ClientIp).
     */
    'trust_proxy' => filter_var(env('TRUST_PROXY', false), FILTER_VALIDATE_BOOL),

    /*
     * Sécurité (voir SECURITE.md : les 20 règles).
     *  - forcer_https      : en production, toute requête reçue en HTTP est redirigée vers HTTPS (301), sauf /up (santé). Actif seulement
     *                        si TRUST_PROXY=true : sans lui, l'application ne peut pas savoir que le proxy a déjà terminé le HTTPS
     *                        et redirigerait en boucle (FORCE_HTTPS=false pour le couper, FORCE_HTTPS=true pour l'imposer) ;
     *  - hsts_secondes     : durée pendant laquelle le navigateur refuse de revenir en HTTP (1 an) ;
     *  - remember_minutes  : « Rester connecté » dure 14 jours au plus, et n'existe pas pour un administrateur ;
     *  - confirmation_heures : validité du lien de confirmation d'adresse e-mail reçu à l'inscription ;
     *  - limites           : plafonds de requêtes (par minute, par heure) des points sensibles, voir AppServiceProvider.
     */
    'securite' => [
        // Vide ou absent = par défaut (actif en production derrière un proxy de confiance). Seul un « false » explicite le coupe.
        'forcer_https' => ($f = env('FORCE_HTTPS')) === null || $f === ''
            ? env('APP_ENV') === 'production' && filter_var(env('TRUST_PROXY', false), FILTER_VALIDATE_BOOL)
            : filter_var($f, FILTER_VALIDATE_BOOL),
        'hsts_secondes' => 31_536_000,
        'remember_minutes' => 20_160,
        'confirmation_heures' => 48,
        // EMAIL_CONFIRMATION=false : les comptes sont actifs dès l'inscription, sans e-mail de confirmation. À utiliser tant qu'aucun
        // SMTP n'est configuré (MAIL_MAILER=log). Remettre à true (ou supprimer la variable) dès que les e-mails partent vraiment.
        'confirmation_email' => filter_var(env('EMAIL_CONFIRMATION', true), FILTER_VALIDATE_BOOL),
        // Validité du lien de réinitialisation de mot de passe (même mécanisme que la confirmation d'adresse, en plus court :
        // un lien qui donne accès au compte doit rester valable moins longtemps qu'un lien qui se contente de le confirmer).
        'reinitialisation_minutes' => 60,
        'limites' => [
            'connexion_par_minute_et_ip' => 30,
            'mot_de_passe_par_minute' => 5,
            'confirmation_par_heure_et_email' => 3,
            'confirmation_par_heure_et_ip' => 8,
            'lien_confirmation_par_minute_et_ip' => 20,
            'mot_de_passe_oublie_par_heure_et_email' => 3,
            'mot_de_passe_oublie_par_heure_et_ip' => 8,
            'lien_mot_de_passe_oublie_par_minute_et_ip' => 20,
            'admin_par_minute' => 60,
            'global_utilisateur_par_minute' => 300,
            'global_visiteur_par_minute' => 900,
        ],
    ],

    /*
     * Protection contre la force brute (plan, phase 0, étape 4).
     * Les échecs de connexion sont comptés par e-mail (5) ET par IP (20, plus large : beaucoup
     * d'abonnés mobiles partagent la même IP publique). Fenêtre glissante de 15 minutes.
     */
    'auth' => [
        'fenetre_secondes' => 900,
        'max_echecs_par_email' => 5,
        'max_echecs_par_ip' => 20,
        'max_inscriptions_par_ip' => 8,
    ],

    /*
     * Hôtes supplémentaires autorisés pour les images dans la Content-Security-Policy
     * (ex. le stockage Supabase : "https://xxxx.supabase.co"), séparés par des espaces.
     */
    'csp_img_hosts' => array_values(array_filter(array_merge(
        explode(' ', (string) env('CSP_IMG_HOSTS', '')),
        // Les photos hébergées par Supabase Storage sont autorisées d'office quand ce mode est actif.
        env('MEDIA_DRIVER', 'local') === 'supabase' ? [rtrim((string) env('SUPABASE_URL', ''), '/')] : [],
    ))),

    /*
     * Page /composants : vitrine du kit d'interface avec des données fictives.
     * Active partout sauf en production, sauf si KOUDMAIN_VITRINE=true.
     */
    'vitrine' => filter_var(env('KOUDMAIN_VITRINE', env('APP_ENV') !== 'production'), FILTER_VALIDATE_BOOL),

    'devise' => 'FCFA',

    /*
     * Photos (plan, étape 7).
     *  - "local"    : fichiers dans public/uploads (développement, aucune configuration) ;
     *  - "supabase" : Supabase Storage (production : le disque de Render est effacé à chaque déploiement).
     * Chaque photo se souvient du mode utilisé quand elle a été envoyée : on peut changer de mode sans perdre les anciennes.
     * La clé "service_role" est un SECRET : uniquement dans les variables d'environnement de Render, jamais dans Git
     * ni envoyée au navigateur.
     */
    'media' => [
        'driver' => env('MEDIA_DRIVER', 'local'),
        'supabase' => [
            'url' => rtrim((string) env('SUPABASE_URL', ''), '/'),
            'cle_service' => env('SUPABASE_SERVICE_KEY'),
            'bucket' => env('SUPABASE_BUCKET', 'medias'),
        ],
        'envoi_max_ko' => 5120,          // poids maximal accepté à l'envoi
        'largeur_max' => 1600,           // les photos sont réduites à cette largeur
        'qualite' => 82,
        'pixels_max' => 30_000_000,      // protège la mémoire du serveur (image "bombe")
        'dimensions_min' => [200, 150],  // largeur, hauteur
        'photos_par_prestation' => 6,
    ],

    /*
     * Règles des prestations (reprises de l'ancienne application).
     */
    'prestation' => [
        'prix_min' => 100,
        'prix_max' => 1_000_000,
        // Durées proposées au prestataire (en minutes).
        'durees' => [30, 45, 60, 90, 120, 180, 240, 300, 480, 1440],
    ],

    'catalogue' => [
        'par_page' => 12,
    ],

    /*
     * Argent et commandes (reprend les règles de l'ancienne application : finance.php).
     * Aucune commission : le prestataire reçoit 100 % du prix. Les montants sont en FCFA.
     */
    'finance' => [
        'recharge_min' => 500,
        'retrait_min' => 1000,
        'mouvement_max' => 1_000_000,
        'quantite_max' => 20,
        // Passé ce délai après « Terminée », sans confirmation ni litige du client, le paiement est libéré au prestataire.
        'liberation_auto_jours' => 3,
        // Rechargement : les opérateurs Mobile Money de Côte d'Ivoire (et la carte bancaire quand l'agrégateur la gère).
        'methodes_recharge' => ['Orange Money', 'MTN MoMo', 'Wave', 'Carte bancaire'],
        'methodes_retrait' => ['Orange Money', 'MTN MoMo', 'Wave', 'Virement bancaire'],
        'montants_rapides' => [2000, 5000, 10000, 25000, 50000],
    ],

    /*
     * Réservation : combien de jours à l'avance un client peut choisir un créneau, et le délai minimal avant l'intervention.
     */
    'reservation' => [
        'jours' => 14,
        'delai_minimal_heures' => 2,
        'pas_minutes' => 30,
    ],

    /*
     * Cartes bancaires du wallet. Un nouvel utilisateur n'en a AUCUNE : il les ajoute lui-même (numéro, expiration, code de sécurité,
     * titulaire, adresse de facturation). Seuls le réseau, les 4 derniers chiffres, l'expiration, le titulaire et l'adresse sont
     * conservés : jamais le numéro complet ni le code de sécurité. La première carte ajoutée devient la carte par défaut.
     * On peut en avoir jusqu'à "max", les geler (elles ne paient plus) ou les supprimer.
     */
    'cartes' => [
        'max' => 5,
        'reseaux' => ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express'],
        'couleurs' => ['emerald' => 'Émeraude', 'saphir' => 'Saphir', 'midnight' => 'Minuit', 'amber' => 'Ambre', 'platinum' => 'Platine', 'silver' => 'Argent'],
        'expiration_max_annees' => 10,
    ],

    /*
     * Modes de paiement d'une commande.
     *  - "physique"     : le client paie le prestataire en main propre. Aucun argent ne passe par KoudMain, pas de séquestre ;
     *  - "mobile_money" : le montant est prélevé sur le wallet (alimenté par Mobile Money) et bloqué en séquestre ;
     *  - "carte"        : idem, mais le prélèvement passe par l'une des cartes enregistrées (elle ne doit pas être gelée).
     */
    'modes_paiement' => [
        'physique' => 'Paiement physique',
        'mobile_money' => 'Mobile Money',
        'carte' => 'Carte bancaire',
    ],

    /*
     * Paiement Mobile Money.
     *  - "aucun"      : aucune recharge possible (valeur par défaut en production : on ne fait jamais semblant d'encaisser) ;
     *  - "simulation" : la recharge réussit tout de suite, SANS argent réel (développement et démonstration) ;
     *  - "cinetpay"   : CinetPay (Orange Money, MTN, Wave, carte) : CINETPAY_API_KEY et CINETPAY_SITE_ID sont requis,
     *                   CINETPAY_SECRET_KEY sert à vérifier la signature (en-tête x-token) des notifications.
     *
     * Une recharge encore « en attente » est revérifiée toutes les 5 minutes auprès de l'agrégateur (filet de sécurité si la
     * notification ne nous parvient pas) puis abandonnée au bout de `expiration_heures`.
     */
    'paiement' => [
        'driver' => env('PAIEMENT_DRIVER', env('APP_ENV') === 'production' ? 'aucun' : 'simulation'),
        'cinetpay' => [
            'api_key' => env('CINETPAY_API_KEY'),
            'site_id' => env('CINETPAY_SITE_ID'),
            'secret_key' => env('CINETPAY_SECRET_KEY'),
            // Refuser toute notification dont la signature x-token est fausse. On ne peut la couper (diagnostic) qu'HORS production :
            // en production elle est toujours vérifiée, et sans clé secrète toute notification est refusée (voir PaiementCinetPay).
            'verifier_signature' => env('APP_ENV') === 'production' ? true : (bool) env('CINETPAY_VERIFIER_SIGNATURE', true),
            'url' => env('CINETPAY_URL', 'https://api-checkout.cinetpay.com/v2'),
        ],
        'rattrapage_apres_minutes' => 2,
        'expiration_heures' => 48,
    ],

    /*
     * Temps réel (lot 4) : le navigateur garde un flux ouvert (Server-Sent Events) et reçoit messages, notifications et
     * changements de commande sans actualiser. Le flux se referme après "duree_flux" secondes et le navigateur le rouvre
     * aussitôt : c'est ce qui évite d'immobiliser un processus du serveur indéfiniment.
     *  - actif      : TEMPS_REEL_ACTIF=false coupe le flux (les pages fonctionnent, sans mise à jour automatique) ;
     *  - pause_ms   : délai entre deux vérifications côté serveur (plus court = plus réactif, mais plus de requêtes) ;
     *  - conservation_minutes : durée de vie d'un événement dans la boîte aux lettres temps réel.
     */
    'temps_reel' => [
        'actif' => (bool) env('TEMPS_REEL_ACTIF', true),
        // « auto » (recommandé) : flux SSE quand le serveur peut traiter plusieurs requêtes à la fois (Apache, php-fpm...),
        // simple interrogation toutes les 3 s quand il ne le peut pas (serveur de développement de PHP sous Windows, ou sans
        // PHP_CLI_SERVER_WORKERS) : un flux y bloquerait TOUTES les autres pages. « flux » ou « sondage » forcent un mode.
        'mode' => env('TEMPS_REEL_MODE', 'auto'),
        'duree_flux' => (int) env('TEMPS_REEL_DUREE', 25),
        'pause_ms' => (int) env('TEMPS_REEL_PAUSE_MS', 800),
        'conservation_minutes' => 120,
    ],

    /*
     * Messagerie : une conversation par commande, entre son client et son prestataire.
     */
    'messagerie' => [
        'longueur_max' => 2000,
        'par_page' => 40,
    ],

    /*
     * Notifications : toujours visibles dans le site (cloche) ; l'e-mail peut être coupé globalement (NOTIFICATIONS_EMAIL=false)
     * ou par chaque utilisateur (Mon profil). En local, MAIL_MAILER=log écrit les e-mails dans storage/logs.
     */
    'notifications' => [
        'email' => (bool) env('NOTIFICATIONS_EMAIL', true),
    ],

    /*
     * Lot 5 : suivi des erreurs (Sentry), tâches planifiées, métriques.
     *  - sentry : SENTRY_DSN vide = rien n'est envoyé. Voir App\Services\Surveillance\Sentry ;
     *  - taches : combien de temps on garde chaque sorte de donnée avant de la purger (tâche « koudmain:nettoyer », chaque nuit) ;
     *    le planificateur est jugé « en panne » quand son battement (chaque minute) a plus de `battement_max_secondes` ;
     *  - metriques : durée du cache de la page « Métriques » (secondes) — la page reste vivante sans marteler la base.
     */
    'sentry' => [
        'dsn' => env('SENTRY_DSN'),
        'environnement' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
        'version' => env('APP_VERSION', 'koudmain@dev'),
    ],

    'taches' => [
        'notifications_lues_jours' => 30,
        'notifications_jours' => 180,
        'metriques_evenements_jours' => 180,
        'metriques_etats_jours' => 730,
        'journaux_jours' => 30,
        'battement_max_secondes' => 180,
    ],

    'metriques' => [
        'cache_secondes' => (int) env('METRIQUES_CACHE', 120),
        'periodes' => [7, 30, 90],
    ],
];
