<?php

namespace App\Providers;

use App\Events\CommandeChangee;
use App\Events\CommandePassee;
use App\Events\RetraitDemande;
use App\Events\RetraitTraite;
use App\Models\User;
use App\Services\Notifications\Ecouteur;
use App\Services\Surveillance\Audit;
use App\Services\Surveillance\Sentry;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaManager;
use App\Services\Media\StockageLocal;
use App\Services\Media\StockageSupabase;
use App\Support\ClientIp;
use App\Support\Saisie;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use App\Support\Referentiel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un seul client Sentry par requête : il compte ses envois pour ne pas inonder Sentry si une boucle échoue.
        $this->app->singleton(Sentry::class);

        // Les photos : le mode (dossier local ou Supabase) se choisit par MEDIA_DRIVER, voir config/koudmain.php.
        $this->app->singleton(MediaManager::class, function (): MediaManager {
            $media = config('koudmain.media');

            return new MediaManager(
                new ImageProcessor(
                    largeurMax: $media['largeur_max'],
                    qualite: $media['qualite'],
                    pixelsMax: $media['pixels_max'],
                    largeurMin: $media['dimensions_min'][0],
                    hauteurMin: $media['dimensions_min'][1],
                ),
                [
                    'local' => new StockageLocal(),
                    'supabase' => new StockageSupabase($media['supabase']['url'], $media['supabase']['cle_service'], $media['supabase']['bucket']),
                ],
                $media['driver'],
            );
        });
    }

    public function boot(): void
    {
        // Erreurs (règle 14) : en production, jamais de page de débogage, même si APP_DEBUG=true est resté par erreur dans les
        // variables d'environnement. Le visiteur ne voit qu'un message générique ; le détail reste dans le journal du serveur.
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
        }

        // Hors production, une relation chargée "à la volée" dans une boucle (le fameux problème N+1)
        // lève une erreur au lieu de ralentir l'application en silence.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Render termine le HTTPS puis parle en HTTP à l'application : on force les liens en https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');

            // Tous les liens absolus (e-mails, retour de paiement, redirections) partent d'APP_URL, jamais de l'en-tête Host de la
            // requête : ce dernier est écrit par le visiteur, et un lien d'e-mail qui pointe vers son site est une attaque classique.
            // Sans effet si APP_URL est encore une adresse locale (le contrôle de production le signale).
            $racine = rtrim((string) config('app.url'), '/');
            $hote = (string) parse_url($racine, PHP_URL_HOST);

            if ($hote !== '' && ! in_array($hote, ['localhost', '127.0.0.1', '::1'], true)) {
                URL::forceRootUrl($racine);
            }
        }

        // Qui est prévenu de quoi (cloche, e-mail, pages qui se mettent à jour en direct) : voir Ecouteur.
        Event::listen(CommandePassee::class, [Ecouteur::class, 'commandePassee']);
        Event::listen(CommandeChangee::class, [Ecouteur::class, 'commandeChangee']);
        Event::listen(RetraitDemande::class, [Ecouteur::class, 'retraitDemande']);
        Event::listen(RetraitTraite::class, [Ecouteur::class, 'retraitTraite']);

        // Le journal des actions qui touchent à l'argent ou à la confiance (lot 5) : qui, quoi, combien, sans donnée sensible.
        Event::listen(CommandePassee::class, [Audit::class, 'commandePassee']);
        Event::listen(CommandeChangee::class, [Audit::class, 'commandeChangee']);
        Event::listen(RetraitDemande::class, [Audit::class, 'retraitDemande']);
        Event::listen(RetraitTraite::class, [Audit::class, 'retraitTraite']);

        // Les listes presque immuables (catégories, services, villes, quartiers) sont mises en cache : on les vide dès qu'une change.
        Referentiel::surveiller();

        // /prestataires/12 : seul un prestataire validé a un profil public (les autres comptes : 404).
        Route::bind('prestataire', fn (string $valeur) => User::query()
            ->whereKey($valeur)
            ->where('est_prestataire', true)
            ->where('est_valide', true)
            ->firstOrFail());

        // Envoi de photos : 20 par minute et par utilisateur (chaque image est décompressée puis recompressée : c'est coûteux).
        RateLimiter::for('televersement', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()?->id ?? ClientIp::resoudre($request))));

        // Suggestions de la barre de recherche : appelées à chaque pause de frappe, donc plafonnées.
        RateLimiter::for('suggestions', fn (Request $request) => Limit::perMinute(60)->by(ClientIp::resoudre($request)));

        // Lu à CHAQUE requête (et non une fois pour toutes au démarrage) : un réglage modifié à chaud, ou dans un test, est pris en compte.
        $limite = fn (string $cle): int => (int) config('koudmain.securite.limites.'.$cle);

        // Plafond général (règle 3) : un utilisateur connecté a son propre compteur (aucune influence des autres personnes qui partagent
        // son réseau) ; un visiteur est compté par IP, avec une marge plus large (beaucoup d'abonnés mobiles partagent la même IP).
        RateLimiter::for('web', fn (Request $request) => $request->user() !== null
            ? Limit::perMinute($limite('global_utilisateur_par_minute'))->by('u'.$request->user()->id)
            : Limit::perMinute($limite('global_visiteur_par_minute'))->by('ip'.ClientIp::resoudre($request)));

        // Connexion : en plus du décompte des ÉCHECS par e-mail et par IP (ConnexionRequest), un plafond sur le nombre TOTAL d'essais.
        RateLimiter::for('connexion', fn (Request $request) => Limit::perMinute($limite('connexion_par_minute_et_ip'))->by(ClientIp::resoudre($request)));

        // Changement de mot de passe : quelqu'un qui aurait volé une session ne doit pas pouvoir deviner l'ancien mot de passe.
        RateLimiter::for('mot-de-passe', fn (Request $request) => Limit::perMinute($limite('mot_de_passe_par_minute'))->by((string) ($request->user()?->id ?? ClientIp::resoudre($request))));

        // Renvoi du lien de confirmation : borné par adresse ET par IP, que le compte existe ou non (la réponse ne le révèle pas).
        RateLimiter::for('confirmation-email', fn (Request $request) => [
            Limit::perHour($limite('confirmation_par_heure_et_email'))->by('e'.sha1(mb_strtolower(trim(Saisie::chaine($request->input('email')))))),
            Limit::perHour($limite('confirmation_par_heure_et_ip'))->by('i'.ClientIp::resoudre($request)),
        ]);

        // Clic sur le lien reçu par e-mail : on freine quelqu'un qui essaierait des liens au hasard.
        RateLimiter::for('lien-confirmation', fn (Request $request) => Limit::perMinute($limite('lien_confirmation_par_minute_et_ip'))->by(ClientIp::resoudre($request)));

        // Mot de passe oublié : demande du lien, bornée par adresse ET par IP (que le compte existe ou non, règle 16).
        RateLimiter::for('mot-de-passe-oublie', fn (Request $request) => [
            Limit::perHour($limite('mot_de_passe_oublie_par_heure_et_email'))->by('e'.sha1(mb_strtolower(trim(Saisie::chaine($request->input('email')))))),
            Limit::perHour($limite('mot_de_passe_oublie_par_heure_et_ip'))->by('i'.ClientIp::resoudre($request)),
        ]);

        // Mot de passe oublié : clic sur le lien reçu par e-mail, et envoi du nouveau mot de passe (même page, même signature).
        RateLimiter::for('lien-mot-de-passe-oublie', fn (Request $request) => Limit::perMinute($limite('lien_mot_de_passe_oublie_par_minute_et_ip'))->by(ClientIp::resoudre($request)));

        // Actions d'administration (valider, suspendre, supprimer un compte, arbitrer, confirmer un retrait...).
        // Lire une page d'administration n'est pas limité ; modifier quelque chose l'est.
        RateLimiter::for('admin', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute($limite('admin_par_minute'))->by('a'.($request->user()?->id ?? ClientIp::resoudre($request))));

        // Inscription : 8 tentatives par tranche de 15 minutes et par IP (plan, phase 0, étape 4).
        RateLimiter::for('inscription', function (Request $request) {
            return Limit::perMinutes(15, (int) config('koudmain.auth.max_inscriptions_par_ip'))
                ->by(ClientIp::resoudre($request))
                ->response(fn () => back()
                    ->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors(['email' => 'Trop de tentatives d\'inscription. Réessayez dans 15 minutes.']));
        });
    }
}
