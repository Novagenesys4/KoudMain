<?php

use App\Services\PaiementService;
use App\Services\Taches\Suivi;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Tâches planifiées de KoudMain. En production il faut que le planificateur tourne :
 *   - avec Docker (Render) : l'entrypoint lance `php artisan schedule:work` à côté d'Apache (voir docker/entrypoint.sh) ;
 *   - sur un serveur classique : une tâche cron chaque minute → `php artisan schedule:run`.
 * Chaque tâche note son résultat dans la table `taches_planifiees` (page admin « Métriques et santé »).
 */

// Battement : « le planificateur tourne ». La page de santé s'inquiète si ce signe de vie a plus de 3 minutes.
Schedule::call(fn () => app(Suivi::class)->battement())->name('battement')->everyMinute();

// Paiement des prestations terminées que le client n'a jamais confirmées (voir CommandeService::libererExpirees).
Schedule::command('koudmain:liberer-escrows')->hourly()->withoutOverlapping()->onOneServer();

// Recharges Mobile Money restées « en attente » (notification perdue, client parti avant le retour) : revérifiées chez l'agrégateur.
Schedule::call(function (): void {
    app(Suivi::class)->suivre('paiements-rattrapage', function (): string {
        $b = app(PaiementService::class)->rattraper();

        return "{$b['verifiees']} vérifiée(s), {$b['creditees']} créditée(s), {$b['abandonnees']} abandonnée(s)";
    });
})->name('paiements-rattrapage')->everyFiveMinutes()->onOneServer();

// Le temps réel ne garde que des événements récents (le navigateur les consomme dans la seconde) : on purge le reste.
Schedule::call(fn () => app(Suivi::class)->suivre('temps-reel-purge', fn () => app(Diffuseur::class)->purger().' événement(s) purgé(s)'))
    ->name('temps-reel-purge')->everyFifteenMinutes()->onOneServer();

// Chaque nuit (heure d'Abidjan) : l'instantané des indicateurs, puis le nettoyage des données périmées.
Schedule::command('koudmain:instantane-metriques')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('koudmain:nettoyer')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
