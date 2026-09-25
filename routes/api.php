<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\CommandeController;
use App\Http\Controllers\Api\V1\EspaceController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PrestataireController;
use App\Http\Controllers\Api\V1\ReferentielController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de l'application mobile KoudMain — /api/v1
|--------------------------------------------------------------------------
| Authentification : jeton Sanctum, en-tête « Authorization: Bearer <token> » (et « Accept: application/json »).
| Espace : l'application envoie « X-Espace: client|prestataire » (compte à double rôle) ; il ne choisit que le point de vue
| des lectures (commandes, séquestre du wallet), jamais un droit (User::espaceDemande).
| Réponses : toujours { success, message, data } (+ meta pour une liste, + code/errors pour une erreur) : voir ApiResponse.
| Groupe « api » (bootstrap/app.php) : en-têtes de sécurité, numéro de requête, plafond « api » (120/min par utilisateur).
|
| Le webhook CinetPay n'est PAS ici : CinetPay appelle /paiements/notification (routes/web.php), la même adresse que pour
| le site. Une recharge lancée depuis l'application y est confirmée exactement comme une recharge lancée depuis le site.
*/

Route::prefix('v1')->name('api.')->group(function () {
    // ---------------------------------------------------------------- Public
    Route::get('/referentiel/categories', [ReferentielController::class, 'categories'])->name('referentiel.categories');
    Route::get('/referentiel/zones', [ReferentielController::class, 'zones'])->name('referentiel.zones');
    Route::get('/parametres', [ReferentielController::class, 'parametres'])->name('parametres');

    Route::get('/prestations', [CatalogueController::class, 'index'])->name('prestations');
    Route::get('/prestations/{prestation}', [CatalogueController::class, 'show'])->where('prestation', '[a-z0-9\-]{1,200}')->name('prestations.voir');
    Route::get('/prestations/{prestation}/creneaux', [CatalogueController::class, 'creneaux'])->where('prestation', '[a-z0-9\-]{1,200}')->name('prestations.creneaux');
    Route::get('/prestataires/{prestataire}', [CatalogueController::class, 'prestataire'])->where('prestataire', '[0-9]{1,12}')->name('prestataires.voir');

    // ---------------------------------------------------------------- Authentification (visiteur)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:api-auth', 'throttle:api-inscription', 'throttle:api-otp'])->name('register');
        Route::post('/register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:api-auth')->name('register.verify');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-auth')->name('login');
        Route::post('/otp/resend', [AuthController::class, 'resendCode'])->middleware(['throttle:api-auth', 'throttle:api-otp'])->name('otp.resend');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware(['throttle:api-auth', 'throttle:api-otp'])->name('password.forgot');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:api-auth')->name('password.reset');
    });

    // ---------------------------------------------------------------- Connecté (jeton)
    Route::middleware(['auth:sanctum', 'api.compte'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        // Informations personnelles (Phase 10) : prénom, nom, quartier, e-mails de notification, présentation (prestataires).
        Route::put('/auth/me', [AuthController::class, 'update'])->middleware('throttle:20,1,api.profil:')->name('auth.me.modifier');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::post('/auth/phone/send-code', [AuthController::class, 'sendPhoneCode'])->middleware('throttle:api-otp')->name('auth.phone.send');
        Route::post('/auth/phone/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:api-auth')->name('auth.phone.verify');
        // Certification (facultative) : lien de confirmation de l'adresse e-mail, 3 envois par heure au plus.
        Route::post('/auth/email/send-link', [AuthController::class, 'sendEmailLink'])->middleware('throttle:3,60,api.email.lien:')->name('auth.email.lien');

        // Double rôle : ouvrir aussi l'espace client ou prestataire (« Passer en mode… » du Profil).
        Route::post('/auth/espaces/client', [EspaceController::class, 'client'])->middleware('throttle:10,1,api.espaces:')->name('auth.espaces.client');
        Route::post('/auth/espaces/prestataire', [EspaceController::class, 'prestataire'])->middleware('throttle:10,1,api.espaces:')->name('auth.espaces.prestataire');

        // Commandes (client et prestataire : chacun ne voit que les siennes).
        Route::get('/commandes', [CommandeController::class, 'index'])->name('commandes');
        Route::get('/commandes/{commande}', [CommandeController::class, 'show'])->where('commande', '[0-9]{1,12}')->name('commandes.voir');

        // Messagerie et notifications.
        Route::get('/conversations', [MessageController::class, 'conversations'])->name('conversations');
        Route::get('/commandes/{commande}/messages', [MessageController::class, 'index'])->where('commande', '[0-9]{1,12}')->middleware('throttle:120,1,api.messages.fil:')->name('messages');
        Route::post('/commandes/{commande}/messages', [MessageController::class, 'store'])->where('commande', '[0-9]{1,12}')->middleware('throttle:40,1,api.messages.envoyer:')->name('messages.envoyer');
        Route::post('/commandes/{commande}/messages/read', [MessageController::class, 'lu'])->where('commande', '[0-9]{1,12}')->name('messages.lu');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::get('/notifications/compteurs', [NotificationController::class, 'compteurs'])->name('notifications.compteurs');
        Route::post('/notifications/read-all', [NotificationController::class, 'toutLire'])->name('notifications.tout-lire');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'lire'])->where('notification', '[0-9a-f\-]{36}')->name('notifications.lire');

        // Espace prestataire (Phase 9) : tableau de bord et semaine type, pour les prestataires validés.
        Route::middleware('api.role:prestataire')->group(function () {
            Route::get('/prestataire/tableau', [PrestataireController::class, 'tableau'])->name('prestataire.tableau');
            Route::get('/prestataire/disponibilites', [PrestataireController::class, 'disponibilites'])->name('prestataire.disponibilites');
        });

        // Wallet : lecture pour tous.
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

        // Tout ce qui engage de l'argent ou un rendez-vous exige un numéro vérifié par SMS.
        Route::middleware('api.telephone')->group(function () {
            Route::post('/commandes', [CommandeController::class, 'store'])->middleware(['api.role:client', 'throttle:20,1,api.commandes.creer:'])->name('commandes.creer');
            Route::post('/commandes/{commande}/actions/{action}', [CommandeController::class, 'agir'])
                ->where('commande', '[0-9]{1,12}')->where('action', '[a-z_]{4,30}')
                ->middleware(['api.role:client,prestataire', 'throttle:30,1,api.commandes.agir:'])->name('commandes.agir');
            Route::post('/commandes/{commande}/avis', [CommandeController::class, 'avis'])->where('commande', '[0-9]{1,12}')->middleware(['api.role:client', 'throttle:20,1,api.avis:'])->name('commandes.avis');

            Route::post('/wallet/recharges', [WalletController::class, 'recharger'])->middleware(['api.role:client', 'throttle:10,1,api.recharges:'])->name('wallet.recharger');
            Route::get('/wallet/recharges/{reference}', [WalletController::class, 'recharge'])->where('reference', '[A-Z0-9]{10,40}')->middleware(['api.role:client', 'throttle:60,1,api.recharges.etat:'])->name('wallet.recharge');

            Route::post('/wallet/retraits', [WalletController::class, 'retirer'])->middleware(['api.role:prestataire', 'throttle:10,1,api.retraits:'])->name('wallet.retirer');
            Route::get('/wallet/retraits', [WalletController::class, 'retraits'])->middleware('api.role:prestataire')->name('wallet.retraits');

            // Semaine type : elle décide des créneaux proposés aux clients.
            Route::put('/prestataire/disponibilites', [PrestataireController::class, 'enregistrerDisponibilites'])
                ->middleware(['api.role:prestataire', 'throttle:20,1,api.disponibilites:'])->name('prestataire.disponibilites.enregistrer');

            // Vérification d'identité : pour les prestataires pas encore validés (le contrôleur vérifie le rôle).
            Route::get('/prestataire/kyc', [KycController::class, 'show'])->name('prestataire.kyc');
            Route::post('/prestataire/kyc', [KycController::class, 'store'])->middleware('throttle:5,1,api.kyc:')->name('prestataire.kyc.envoyer');
        });
    });
});
