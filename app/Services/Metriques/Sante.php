<?php

namespace App\Services\Metriques;

use App\Services\Surveillance\Sentry;
use App\Services\Taches\Suivi;
use App\Services\TempsReel\Diffuseur;
use App\Support\ControleProduction;
use App\Support\Format;
use App\Support\SecuriteBase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La santé du système, vérifiée EN DIRECT (jamais mise en cache : on veut savoir maintenant). Chaque contrôle rend
 *   ['nom', 'niveau' => ok|attention|erreur|info, 'detail']
 * « attention » = ça marche mais ce n'est pas prêt pour de vrais clients (ex. e-mails écrits dans un fichier) ;
 * « erreur » = quelque chose est cassé ou dangereux (ex. mode débogage ouvert en production).
 * Un contrôle qui plante devient lui-même une « erreur » : la page de santé ne doit jamais tomber avec le reste.
 */
class Sante
{
    /** @return list<array{nom: string, niveau: string, detail: string}> */
    public function controles(): array
    {
        $production = app()->isProduction();
        $c = [];

        foreach ([
            fn () => $this->baseDeDonnees(),
            fn () => $this->cache(),
            fn () => $this->planificateur($production),
            fn () => $this->taches(),
            fn () => $this->stockagePhotos($production),
            fn () => $this->emails($production),
            fn () => $this->paiements($production),
            fn () => $this->tempsReel(),
            fn () => $this->suiviDesErreurs($production),
            fn () => $this->environnement($production),
            fn () => $this->securite($production),
            fn () => $this->disque(),
        ] as $controle) {
            try {
                $c[] = $controle();
            } catch (Throwable $e) {
                $c[] = ['nom' => 'Contrôle', 'niveau' => 'erreur', 'detail' => 'Le contrôle a échoué : '.mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return $c;
    }

    /** @param list<array{niveau: string}> $controles @return array{niveau: string, titre: string, erreurs: int, attentions: int} */
    public function synthese(array $controles): array
    {
        $erreurs = count(array_filter($controles, fn ($c) => $c['niveau'] === 'erreur'));
        $attentions = count(array_filter($controles, fn ($c) => $c['niveau'] === 'attention'));

        return [
            'niveau' => $erreurs > 0 ? 'erreur' : ($attentions > 0 ? 'attention' : 'ok'),
            'titre' => $erreurs > 0
                ? Format::pluriel($erreurs, 'problème').' à corriger'
                : ($attentions > 0 ? Format::pluriel($attentions, 'point').' d\'attention' : 'Tout est en ordre'),
            'erreurs' => $erreurs,
            'attentions' => $attentions,
        ];
    }

    private function baseDeDonnees(): array
    {
        $debut = hrtime(true);
        $version = (string) DB::selectOne('select version() as v')->v;
        $ms = (int) round((hrtime(true) - $debut) / 1_000_000);

        // « PostgreSQL 16.4 (Debian ...) on x86_64... » -> « PostgreSQL 16.4 »
        preg_match('/^PostgreSQL [\d.]+/', $version, $m);
        $nom = $m[0] ?? 'Base de données';

        return [
            'nom' => 'Base de données',
            'niveau' => $ms > 800 ? 'attention' : 'ok',
            'detail' => "$nom · réponse en $ms ms".($ms > 800 ? ' (lente : la base est-elle loin du serveur ?)' : ''),
        ];
    }

    private function cache(): array
    {
        $cle = 'sante.'.bin2hex(random_bytes(4));
        Cache::put($cle, 'ok', 10);
        $lu = Cache::get($cle);
        Cache::forget($cle);

        return [
            'nom' => 'Cache et sessions',
            'niveau' => $lu === 'ok' ? 'ok' : 'erreur',
            'detail' => $lu === 'ok' ? 'Cache « '.config('cache.default').' », sessions « '.config('session.driver').' »' : 'Le cache « '.config('cache.default').' » ne conserve pas les valeurs.',
        ];
    }

    private function planificateur(bool $production): array
    {
        $dernier = DB::table('taches_planifiees')->where('nom', Suivi::BATTEMENT)->value('derniere_execution');

        if ($dernier !== null && app(Suivi::class)->planificateurVivant()) {
            $age = max(0, (int) now()->diffInSeconds($dernier, true));

            return ['nom' => 'Planificateur de tâches', 'niveau' => 'ok', 'detail' => 'Actif : dernier battement il y a '.Format::duree($age)];
        }

        return [
            'nom' => 'Planificateur de tâches',
            'niveau' => $production ? 'erreur' : 'attention',
            'detail' => $dernier === null
                ? 'Jamais lancé. Les paiements non confirmés ne sont pas libérés et rien n\'est nettoyé : lancez « php artisan schedule:work » (Docker le fait tout seul).'
                : 'Arrêté : dernier battement il y a '.Format::duree(max(0, (int) now()->diffInSeconds($dernier, true))).'.',
        ];
    }

    private function taches(): array
    {
        $enErreur = app(Suivi::class)->toutes()->where('statut', 'erreur')->pluck('nom');

        return [
            'nom' => 'Dernières exécutions',
            'niveau' => $enErreur->isEmpty() ? 'ok' : 'erreur',
            'detail' => $enErreur->isEmpty() ? 'Aucune tâche en échec à sa dernière exécution.' : 'En échec : '.$enErreur->implode(', ').' (voir le tableau plus bas et les journaux).',
        ];
    }

    private function stockagePhotos(bool $production): array
    {
        $mode = (string) config('koudmain.media.driver');

        if ($mode === 'supabase') {
            $s = config('koudmain.media.supabase');
            $complet = $s['url'] !== '' && filled($s['cle_service']);

            return [
                'nom' => 'Stockage des photos',
                'niveau' => $complet ? 'ok' : 'erreur',
                'detail' => $complet ? 'Supabase Storage, bucket « '.$s['bucket'].' »' : 'Supabase choisi mais SUPABASE_URL ou SUPABASE_SERVICE_KEY manque : les envois de photos échoueront.',
            ];
        }

        return [
            'nom' => 'Stockage des photos',
            'niveau' => $production ? 'attention' : 'ok',
            'detail' => $production
                ? 'Dossier local : le disque de Render est effacé à chaque déploiement, les photos disparaîtraient. Utilisez MEDIA_DRIVER=supabase.'
                : 'Dossier local (public/uploads), adapté au développement.',
        ];
    }

    private function emails(bool $production): array
    {
        $mailer = (string) config('mail.default');
        $muet = in_array($mailer, ['log', 'array'], true);

        return [
            'nom' => 'E-mails',
            'niveau' => $muet && $production ? 'attention' : 'ok',
            'detail' => $muet
                ? 'Envoi « '.$mailer.' » : les e-mails sont seulement écrits dans les journaux'.($production ? ' — aucun client ne les reçoit. Configurez MAIL_MAILER=smtp.' : ' (normal en développement).')
                : 'Envoi « '.$mailer.' » depuis '.config('mail.from.address').(config('koudmain.notifications.email') ? '' : ' (désactivé : NOTIFICATIONS_EMAIL=false)'),
        ];
    }

    private function paiements(bool $production): array
    {
        $mode = (string) config('koudmain.paiement.driver');

        return match (true) {
            $mode === 'simulation' && $production => ['nom' => 'Paiement Mobile Money', 'niveau' => 'erreur', 'detail' => 'La SIMULATION est active en production : les recharges réussissent sans aucun argent réel. Mettez PAIEMENT_DRIVER=cinetpay (ou aucun).'],
            $mode === 'simulation' => ['nom' => 'Paiement Mobile Money', 'niveau' => 'ok', 'detail' => 'Simulation (développement) : aucun argent réel.'],
            $mode === 'cinetpay' => $this->controleCinetPay($production),
            default => ['nom' => 'Paiement Mobile Money', 'niveau' => $production ? 'attention' : 'info', 'detail' => 'Aucun agrégateur : les clients ne peuvent pas recharger leur wallet. Choisissez PAIEMENT_DRIVER=cinetpay quand votre compte est prêt.'],
        };
    }

    private function controleCinetPay(bool $production): array
    {
        $nom = 'Paiement Mobile Money';
        $hote = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $public = $hote !== '' && ! in_array($hote, ['localhost', '127.0.0.1', '::1'], true) && ! str_ends_with($hote, '.test') && ! str_ends_with($hote, '.local');

        if (! filled(config('koudmain.paiement.cinetpay.api_key')) || ! filled(config('koudmain.paiement.cinetpay.site_id'))) {
            return ['nom' => $nom, 'niveau' => 'erreur', 'detail' => 'CinetPay est choisi mais CINETPAY_API_KEY ou CINETPAY_SITE_ID manque.'];
        }

        if (! $public) {
            return ['nom' => $nom, 'niveau' => 'attention', 'detail' => 'CinetPay ne peut pas appeler « '.config('app.url').' » : APP_URL doit être une adresse publique en https (hébergement ou tunnel) pour recevoir les notifications. Le retour du client et le rattrapage (toutes les 5 min) créditent quand même.'];
        }

        if (! filled(config('koudmain.paiement.cinetpay.secret_key'))) {
            return ['nom' => $nom, 'niveau' => 'attention', 'detail' => 'CINETPAY_SECRET_KEY manque : les notifications CinetPay sont REFUSÉES (elles ne peuvent pas être authentifiées). Le retour du client et le rattrapage (toutes les 5 min) créditent quand même, après vérification auprès de CinetPay.'];
        }

        return ['nom' => $nom, 'niveau' => 'ok', 'detail' => 'CinetPay configuré (Orange Money, MTN, Wave, carte), notifications signées.'];
    }

    private function tempsReel(): array
    {
        if (! config('koudmain.temps_reel.actif')) {
            return ['nom' => 'Temps réel', 'niveau' => 'info', 'detail' => 'Désactivé (TEMPS_REEL_ACTIF=false) : les pages ne se mettent pas à jour toutes seules.'];
        }

        return [
            'nom' => 'Temps réel',
            'niveau' => 'ok',
            'detail' => Diffuseur::mode() === 'flux' ? 'Flux continu (SSE) : messages et notifications instantanés.' : 'Interrogation toutes les 3 s (serveur qui ne peut traiter qu\'une requête à la fois).',
        ];
    }

    private function suiviDesErreurs(bool $production): array
    {
        return app(Sentry::class)->actif()
            ? ['nom' => 'Suivi des erreurs (Sentry)', 'niveau' => 'ok', 'detail' => 'Actif : les erreurs inattendues sont envoyées à Sentry.']
            : ['nom' => 'Suivi des erreurs (Sentry)', 'niveau' => $production ? 'attention' : 'info', 'detail' => 'Non configuré (SENTRY_DSN vide) : une erreur ne sera vue que dans les journaux.'];
    }

    private function environnement(bool $production): array
    {
        $debug = (bool) config('app.debug');
        $http = ! $production || request()->isSecure();
        $problemes = array_filter([
            $production && $debug ? 'APP_DEBUG=true en production : les erreurs affichent le code et les secrets. À passer à false d\'urgence.' : null,
            ! $http ? 'La page n\'est pas servie en HTTPS.' : null,
        ]);

        return [
            'nom' => 'Environnement',
            'niveau' => $production && $debug ? 'erreur' : (! $http ? 'attention' : 'ok'),
            'detail' => $problemes !== []
                ? implode(' ', $problemes)
                : 'Environnement « '.config('app.env').' » · PHP '.PHP_VERSION.' · Laravel '.Application::VERSION.' · version '.config('koudmain.sentry.version'),
        ];
    }

    /** Règles de sécurité vérifiées en direct : configuration de production (variables d'environnement) et RLS de la base. */
    private function securite(bool $production): array
    {
        $problemes = $production ? ControleProduction::verifier() : [];
        $sansRls = SecuriteBase::sansProtection();

        $erreurs = array_column(array_filter($problemes, fn ($p) => $p['niveau'] === 'erreur'), 'message');
        $attentions = array_column(array_filter($problemes, fn ($p) => $p['niveau'] === 'attention'), 'message');

        if ($sansRls !== []) {
            $attentions[] = 'RLS absente sur '.count($sansRls).' table(s) ('.implode(', ', array_slice($sansRls, 0, 5)).') : lancez php artisan koudmain:securiser-base.';
        }

        if ($erreurs !== []) {
            return ['nom' => 'Sécurité', 'niveau' => 'erreur', 'detail' => implode(' ', array_slice($erreurs, 0, 2)).(count($erreurs) > 2 ? ' (+ '.(count($erreurs) - 2).' autre(s))' : '')];
        }

        if ($attentions !== []) {
            return ['nom' => 'Sécurité', 'niveau' => 'attention', 'detail' => implode(' ', array_slice($attentions, 0, 2)).(count($attentions) > 2 ? ' (+ '.(count($attentions) - 2).' autre(s))' : '')];
        }

        return ['nom' => 'Sécurité', 'niveau' => 'ok', 'detail' => $production ? 'Configuration de production conforme, RLS active sur toutes les tables.' : 'RLS active sur toutes les tables (le contrôle de configuration ne s\'applique qu\'en production).'];
    }

    private function disque(): array
    {
        $libre = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($libre === false || $total === false || $total <= 0) {
            return ['nom' => 'Espace disque', 'niveau' => 'info', 'detail' => 'Indisponible sur ce serveur.'];
        }

        $pct = (int) round($libre / $total * 100);

        return [
            'nom' => 'Espace disque',
            'niveau' => $libre < 300 * 1_048_576 ? 'erreur' : ($libre < 2 * 1_073_741_824 ? 'attention' : 'ok'),
            'detail' => "$pct % libre (".number_format($libre / 1_073_741_824, 1, ',', '').' Go)',
        ];
    }
}
