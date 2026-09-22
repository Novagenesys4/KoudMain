<?php

namespace App\Console\Commands;

use App\Services\Metriques\Enregistreur;
use App\Services\Taches\Suivi;
use Illuminate\Console\Command;

/**
 * Tâche planifiée (chaque nuit) : photographie l'état de la plateforme (comptes, prestataires, commandes en cours, argent en
 * séquestre...) pour tracer des courbes dans la page « Métriques et santé ». Une seule photo par jour, sauf avec --force.
 */
class InstantaneMetriques extends Command
{
    protected $signature = 'koudmain:instantane-metriques {--force : Reprendre une photo même s\'il y en a déjà une aujourd\'hui}';

    protected $description = 'Enregistre l\'instantané quotidien des indicateurs de la plateforme.';

    public function handle(Suivi $suivi, Enregistreur $metriques): int
    {
        $lignes = $suivi->suivre('instantane-metriques', function () use ($metriques): string {
            $n = $metriques->instantane((bool) $this->option('force'));

            return $n === 0 ? 'déjà pris aujourd\'hui' : "$n indicateur(s) enregistré(s)";
        });

        if ($lignes === null) {
            $this->error('L\'instantané a échoué (voir les journaux).');

            return self::FAILURE;
        }

        $this->info($lignes.'.');

        return self::SUCCESS;
    }
}
