<?php

namespace App\Console\Commands;

use App\Services\Taches\Suivi;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Tâche planifiée (chaque nuit) : purge ce qui ne sert plus, pour que la base et le disque ne grossissent pas sans fin.
 * Les durées de conservation sont dans config/koudmain.php (« taches »). Seules des données de confort sont purgées :
 * jamais une commande, un paiement, un avis ni un message.
 */
class Nettoyer extends Command
{
    protected $signature = 'koudmain:nettoyer';

    protected $description = 'Purge les vieilles notifications, événements temps réel, métriques et journaux.';

    public function handle(Suivi $suivi): int
    {
        $resume = $suivi->suivre('nettoyage', function (): string {
            $c = config('koudmain.taches');

            $lues = DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays($c['notifications_lues_jours']))->delete();
            $anciennes = DB::table('notifications')->where('created_at', '<', now()->subDays($c['notifications_jours']))->delete();
            $evenements = DB::table('metriques')->where('nom', 'not like', 'etat.%')->where('created_at', '<', now()->subDays($c['metriques_evenements_jours']))->delete();
            $etats = DB::table('metriques')->where('nom', 'like', 'etat.%')->where('created_at', '<', now()->subDays($c['metriques_etats_jours']))->delete();
            $flux = app(Diffuseur::class)->purger();
            $journaux = $this->purgerJournaux((int) $c['journaux_jours']);

            return sprintf(
                '%d notification(s), %d métrique(s), %d événement(s) temps réel, %d journal(aux) purgés',
                $lues + $anciennes, $evenements + $etats, $flux, $journaux,
            );
        });

        if ($resume === null) {
            $this->error('Le nettoyage a échoué (voir les journaux).');

            return self::FAILURE;
        }

        $this->info($resume.'.');

        return self::SUCCESS;
    }

    /** Supprime les fichiers de journaux (storage/logs/*.log) plus vieux que N jours, sauf le journal du jour. */
    private function purgerJournaux(int $jours): int
    {
        $limite = now()->subDays($jours)->getTimestamp();
        $supprimes = 0;

        foreach (File::glob(storage_path('logs/*.log')) ?: [] as $fichier) {
            if (File::lastModified($fichier) < $limite && @unlink($fichier)) {
                $supprimes++;
            }
        }

        return $supprimes;
    }
}
