<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\Auth\ConnexionController;
use App\Http\Controllers\Auth\ConfirmationEmailController;
use App\Http\Controllers\Auth\InscriptionController;
use App\Http\Controllers\Auth\MotDePasseOublieController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\Client;
use App\Http\Controllers\CompteController;
use App\Http\Controllers\MessagerieController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TempsReelController;
use App\Http\Controllers\Espace\CommandeController;
use App\Http\Controllers\Espace\CarteVirtuelleController;
use App\Http\Controllers\Espace\WalletController;
use App\Http\Controllers\PaiementRetourController;
use App\Http\Controllers\Prestataire;
use App\Http\Controllers\Prestataire\PrestationController;
use App\Http\Controllers\Prestataire\PrestationPhotoController;
use App\Http\Controllers\PrestataireProfilController;
use App\Http\Controllers\PrestationPubliqueController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\SuggestionsController;
use App\Http\Controllers\TableauDeBordController;
use Illuminate\Support\Facades\Route;

Route::get('/', AccueilController::class)->name('accueil');

// Pages publiques : le catalogue, une prestation (par son slug), le profil d'un prestataire.
Route::get('/prestations', [CatalogueController::class, 'index'])->name('catalogue');
Route::get('/prestations/{prestation:slug}', [PrestationPubliqueController::class, 'show'])->name('prestations.voir');
Route::get('/prestataires/{prestataire}', [PrestataireProfilController::class, 'show'])->where('prestataire', '[0-9]{1,12}')->name('prestataires.voir');
Route::get('/recherche/suggestions', SuggestionsController::class)->middleware('throttle:suggestions')->name('recherche.suggestions');

// Adresses appelées par l'agrégateur de paiement Mobile Money (sans jeton CSRF : voir bootstrap/app.php).
Route::match(['get', 'post'], '/paiements/retour', [PaiementRetourController::class, 'retour'])->name('paiements.retour');
Route::post('/paiements/notification', [PaiementRetourController::class, 'notification'])->middleware('throttle:60,1,paiements.notification:')->name('paiements.notification');

// Vitrine des composants d'interface (données fictives). Active en développement, désactivée par défaut
// en production ; KOUDMAIN_VITRINE=true permet de la montrer (démonstration, soutenance...).
if (config('koudmain.vitrine')) {
    Route::view('/composants', 'composants')->name('composants');
}

// Réservé aux visiteurs non connectés (un utilisateur connecté est renvoyé vers son espace).
Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionController::class, 'afficher'])->name('connexion');
    Route::post('/connexion', [ConnexionController::class, 'connecter'])->middleware('throttle:connexion'); // + décompte des échecs dans ConnexionRequest

    Route::get('/inscription', [InscriptionController::class, 'afficher'])->name('inscription');
    Route::post('/inscription', [InscriptionController::class, 'creer'])->middleware('throttle:inscription');

    Route::get('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'formulaire'])->name('mot-de-passe-oublie');
    Route::post('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'envoyer'])->middleware('throttle:mot-de-passe-oublie')->name('mot-de-passe-oublie.envoyer');
});

// Confirmation de l'adresse e-mail (règle 19). Le lien reçu par e-mail est SIGNÉ (falsification et expiration détectées) ; il ne connecte
// personne : il confirme l'adresse, puis renvoie vers la connexion. Le renvoi du lien répond toujours pareil (règle 16).
Route::get('/email/confirmer/{utilisateur}/{hash}', [ConfirmationEmailController::class, 'confirmer'])
    ->where('utilisateur', '[0-9]{1,12}')->where('hash', '[a-f0-9]{40}')
    ->middleware(['signed:relative', 'throttle:lien-confirmation'])->name('email.confirmer');
Route::get('/email/renvoyer', [ConfirmationEmailController::class, 'formulaire'])->name('email.renvoyer');
Route::post('/email/renvoyer', [ConfirmationEmailController::class, 'renvoyer'])->middleware('throttle:confirmation-email')->name('email.renvoyer.envoyer');

// Réinitialisation du mot de passe (règle 19) : même mécanisme de lien signé que la confirmation d'adresse ci-dessus.
// Le lien ne connecte personne : il permet de choisir un nouveau mot de passe, puis renvoie vers la connexion.
Route::get('/mot-de-passe-oublie/{utilisateur}/{hash}', [MotDePasseOublieController::class, 'reinitialiser'])
    ->where('utilisateur', '[0-9]{1,12}')->where('hash', '[a-f0-9]{40}')
    ->middleware(['signed:relative', 'throttle:lien-mot-de-passe-oublie'])->name('mot-de-passe-oublie.reinitialiser');
Route::post('/mot-de-passe-oublie/{utilisateur}/{hash}', [MotDePasseOublieController::class, 'enregistrer'])
    ->where('utilisateur', '[0-9]{1,12}')->where('hash', '[a-f0-9]{40}')
    ->middleware(['signed:relative', 'throttle:lien-mot-de-passe-oublie'])->name('mot-de-passe-oublie.enregistrer');

// « email.confirme » : filet de sécurité, un compte dont l'adresse n'est pas confirmée n'entre dans aucun espace.
Route::middleware(['auth', 'email.confirme'])->group(function () {
    // Les commandes ont la même forme dans les trois espaces : liste, détail, action (le rôle arrive en paramètre par défaut).
    $commandesDe = function (string $role): void {
        Route::get('/commandes', [CommandeController::class, 'index'])->defaults('role', $role)->name('commandes');
        Route::get('/commandes/{commande}', [CommandeController::class, 'show'])->defaults('role', $role)->where('commande', '[0-9]{1,12}')->name('commandes.voir');

        if ($role !== 'admin') {
            Route::post('/commandes/{commande}/{action}', [CommandeController::class, 'agir'])->defaults('role', $role)
                ->where('commande', '[0-9]{1,12}')->where('action', '[a-z_]{4,30}')->middleware('throttle:30,1,commandes.agir:')->name('commandes.agir');
        }
    };

    Route::post('/deconnexion', [ConnexionController::class, 'deconnecter'])->name('deconnexion');

    Route::get('/compte/mot-de-passe', [CompteController::class, 'motDePasse'])->name('compte.mot-de-passe');
    Route::put('/compte/mot-de-passe', [CompteController::class, 'changerMotDePasse'])->middleware('throttle:mot-de-passe')->name('compte.mot-de-passe.modifier');

    Route::get('/compte/profil', [ProfilController::class, 'edit'])->name('compte.profil');
    Route::put('/compte/profil', [ProfilController::class, 'update'])->name('compte.profil.modifier');
    Route::put('/compte/identite', [ProfilController::class, 'modifierIdentite'])->name('compte.identite');
    Route::put('/compte/notifications', [ProfilController::class, 'preferencesNotifications'])->name('compte.notifications');
    Route::post('/compte/avatar', [ProfilController::class, 'envoyerAvatar'])->middleware('throttle:televersement')->name('compte.avatar');
    Route::delete('/compte/avatar', [ProfilController::class, 'supprimerAvatar'])->name('compte.avatar.supprimer');

    Route::get('/tableau-de-bord', [TableauDeBordController::class, 'rediriger'])->name('tableau-de-bord');
    Route::get('/paiements/{reference}/continuer', [PaiementRetourController::class, 'continuer'])->where('reference', '[A-Z0-9]{10,40}')->name('paiements.continuer');

    // Temps réel : le flux (Server-Sent Events) que chaque page connectée ouvre, et son rattrapage en secours.
    Route::get('/temps-reel', [TempsReelController::class, 'flux'])->name('temps-reel');
    Route::get('/temps-reel/sonder', [TempsReelController::class, 'sonder'])->middleware('throttle:60,1,temps-reel.sonder:')->name('temps-reel.sonder');

    // Notifications : la page, la liste de la cloche, « lu ».
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/recentes', [NotificationController::class, 'recentes'])->middleware('throttle:60,1,notifications.recentes:')->name('notifications.recentes');
    Route::post('/notifications/tout-lire', [NotificationController::class, 'toutLire'])->middleware('throttle:30,1,notifications.tout-lire:')->name('notifications.tout-lire');
    Route::post('/notifications/{notification}', [NotificationController::class, 'lire'])->where('notification', '[0-9a-f-]{36}')->middleware('throttle:60,1,notifications.lire:')->name('notifications.lire');

    // Messagerie : une discussion par commande, entre son client et son prestataire.
    Route::get('/messages', [MessagerieController::class, 'index'])->name('messages');
    Route::get('/messages/{commande}', [MessagerieController::class, 'index'])->where('commande', '[0-9]{1,12}')->name('messages.voir');
    Route::get('/messages/{commande}/fil', [MessagerieController::class, 'fil'])->where('commande', '[0-9]{1,12}')->middleware('throttle:120,1,messages.fil:')->name('messages.fil');
    Route::post('/messages/{commande}', [MessagerieController::class, 'envoyer'])->where('commande', '[0-9]{1,12}')->middleware('throttle:40,1,messages.envoyer:')->name('messages.envoyer');
    Route::post('/messages/{commande}/lu', [MessagerieController::class, 'lu'])->where('commande', '[0-9]{1,12}')->middleware('throttle:120,1,messages.lu:')->name('messages.lu');
    Route::post('/messages/{commande}/ecrit', [MessagerieController::class, 'ecrit'])->where('commande', '[0-9]{1,12}')->middleware('throttle:60,1,messages.ecrit:')->name('messages.ecrit');

    // ------------------------------------------------------------------ Espace client
    Route::middleware('role:client')->prefix('client')->name('client.')->group(function () use ($commandesDe) {
        Route::get('/', Client\TableauDeBordController::class)->name('tableau-de-bord');
        Route::get('/catalogue', [Client\CatalogueController::class, 'index'])->name('catalogue');
        Route::get('/favoris', [Client\FavoriController::class, 'index'])->name('favoris');
        Route::post('/favoris/{prestation:slug}', [Client\FavoriController::class, 'basculer'])->middleware('throttle:60,1,favoris.basculer:')->name('favoris.basculer');

        // Noter une prestation d'une commande terminée.
        Route::post('/commandes/{commande}/avis', [Client\AvisController::class, 'enregistrer'])->where('commande', '[0-9]{1,12}')->middleware('throttle:20,1,commandes.avis:')->name('commandes.avis');

        // Commander : choisir le créneau, envoyer (l'argent est mis en séquestre).
        Route::get('/prestations/{prestation:slug}/commander', [Client\PasserCommandeController::class, 'create'])->name('commander');
        Route::post('/prestations/{prestation:slug}/commander', [Client\PasserCommandeController::class, 'store'])->middleware('throttle:20,1,commander.envoyer:')->name('commander.envoyer');

        $commandesDe('client');

        Route::get('/wallet', [WalletController::class, 'show'])->defaults('role', 'client')->name('wallet');
        Route::get('/wallet/export', [WalletController::class, 'exporter'])->defaults('role', 'client')->middleware('throttle:10,1,wallet.export:')->name('wallet.export');
        Route::post('/wallet/cartes', [CarteVirtuelleController::class, 'creer'])->defaults('role', 'client')->middleware('throttle:8,1,wallet.cartes.creer:')->name('wallet.cartes.creer');
        Route::patch('/wallet/cartes/{carte}/gel', [CarteVirtuelleController::class, 'geler'])->defaults('role', 'client')->whereNumber('carte')->middleware('throttle:20,1,wallet.cartes.geler:')->name('wallet.cartes.geler');
        Route::delete('/wallet/cartes/{carte}', [CarteVirtuelleController::class, 'supprimer'])->defaults('role', 'client')->whereNumber('carte')->middleware('throttle:20,1,wallet.cartes.supprimer:')->name('wallet.cartes.supprimer');
        Route::post('/wallet/recharger', [WalletController::class, 'recharger'])->middleware('throttle:10,1,wallet.recharger:')->name('wallet.recharger');
    });

    // ------------------------------------------------------------- Espace prestataire (validé)
    Route::middleware('role:prestataire')->prefix('prestataire')->name('prestataire.')->group(function () use ($commandesDe) {
        Route::get('/', Prestataire\TableauDeBordController::class)->name('tableau-de-bord');

        $commandesDe('prestataire');

        Route::get('/wallet', [WalletController::class, 'show'])->defaults('role', 'prestataire')->name('wallet');
        Route::get('/wallet/export', [WalletController::class, 'exporter'])->defaults('role', 'prestataire')->middleware('throttle:10,1,wallet.export:')->name('wallet.export');
        Route::post('/wallet/cartes', [CarteVirtuelleController::class, 'creer'])->defaults('role', 'prestataire')->middleware('throttle:8,1,wallet.cartes.creer:')->name('wallet.cartes.creer');
        Route::patch('/wallet/cartes/{carte}/gel', [CarteVirtuelleController::class, 'geler'])->defaults('role', 'prestataire')->whereNumber('carte')->middleware('throttle:20,1,wallet.cartes.geler:')->name('wallet.cartes.geler');
        Route::delete('/wallet/cartes/{carte}', [CarteVirtuelleController::class, 'supprimer'])->defaults('role', 'prestataire')->whereNumber('carte')->middleware('throttle:20,1,wallet.cartes.supprimer:')->name('wallet.cartes.supprimer');
        Route::post('/wallet/retrait', [WalletController::class, 'retirer'])->middleware('throttle:10,1,wallet.retrait:')->name('wallet.retrait');

        Route::get('/disponibilites', [Prestataire\DisponibiliteController::class, 'edit'])->name('disponibilites');
        Route::put('/disponibilites', [Prestataire\DisponibiliteController::class, 'update'])->name('disponibilites.enregistrer');

        Route::prefix('prestations')->name('prestations.')->group(function () {
            Route::get('/', [PrestationController::class, 'index'])->name('index');
            Route::get('/creer', [PrestationController::class, 'create'])->name('creer');
            Route::post('/', [PrestationController::class, 'store'])->middleware('throttle:televersement')->name('enregistrer');
            Route::get('/{prestation:slug}/modifier', [PrestationController::class, 'edit'])->name('modifier');
            Route::put('/{prestation:slug}', [PrestationController::class, 'update'])->middleware('throttle:televersement')->name('mettre-a-jour');
            Route::patch('/{prestation:slug}/activation', [PrestationController::class, 'activation'])->name('activation');
            Route::delete('/{prestation:slug}', [PrestationController::class, 'destroy'])->name('supprimer');

            Route::post('/{prestation:slug}/photos', [PrestationPhotoController::class, 'store'])->middleware('throttle:televersement')->name('photos.ajouter');
            Route::delete('/{prestation:slug}/photos/{media}', [PrestationPhotoController::class, 'destroy'])->where('media', '[0-9]{1,12}')->name('photos.supprimer');
            Route::patch('/{prestation:slug}/photos/{media}/principale', [PrestationPhotoController::class, 'principale'])->where('media', '[0-9]{1,12}')->name('photos.principale');
        });
    });

    // ------------------------------------------------------------------ Administration
    Route::middleware(['role:admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () use ($commandesDe) {
        Route::get('/', Admin\TableauDeBordController::class)->name('tableau-de-bord');

        Route::get('/prestataires', [Admin\PrestataireController::class, 'index'])->name('prestataires');
        Route::post('/prestataires/{utilisateur}/valider', [Admin\PrestataireController::class, 'valider'])->where('utilisateur', '[0-9]{1,12}')->name('prestataires.valider');
        Route::post('/prestataires/{utilisateur}/suspendre', [Admin\PrestataireController::class, 'suspendre'])->where('utilisateur', '[0-9]{1,12}')->name('prestataires.suspendre');

        Route::get('/utilisateurs', [Admin\UtilisateurController::class, 'index'])->name('utilisateurs');
        Route::delete('/utilisateurs/{utilisateur}', [Admin\UtilisateurController::class, 'destroy'])->where('utilisateur', '[0-9]{1,12}')->name('utilisateurs.supprimer');

        Route::get('/catalogue', [Admin\CatalogueController::class, 'index'])->name('catalogue');
        Route::post('/categories', [Admin\CatalogueController::class, 'creerCategorie'])->name('categories.creer');
        Route::delete('/categories/{categorie}', [Admin\CatalogueController::class, 'supprimerCategorie'])->where('categorie', '[0-9]{1,12}')->name('categories.supprimer');
        Route::post('/services', [Admin\CatalogueController::class, 'creerService'])->name('services.creer');
        Route::delete('/services/{service}', [Admin\CatalogueController::class, 'supprimerService'])->where('service', '[0-9]{1,12}')->name('services.supprimer');

        $commandesDe('admin');
        Route::post('/commandes/{commande}/arbitrer', [Admin\CommandeController::class, 'arbitrer'])->where('commande', '[0-9]{1,12}')->name('commandes.arbitrer');

        Route::get('/retraits', [Admin\RetraitController::class, 'index'])->name('retraits');
        Route::post('/retraits/{retrait}/confirmer', [Admin\RetraitController::class, 'confirmer'])->where('retrait', '[0-9]{1,12}')->name('retraits.confirmer');
        Route::post('/retraits/{retrait}/refuser', [Admin\RetraitController::class, 'refuser'])->where('retrait', '[0-9]{1,12}')->name('retraits.refuser');
        Route::get('/metriques', Admin\MetriqueController::class)->name('metriques');
    });
});

// Adresse inconnue : on passe par le groupe "web" pour que l'en-tête sache si l'utilisateur est connecté.
Route::fallback(fn () => abort(404));
