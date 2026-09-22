<?php

namespace App\Console\Commands;

use App\Services\CommandeService;
use App\Services\Taches\Suivi;
use Illuminate\Console\Command;

/**
 * Tâche planifiée (toutes les heures) : les prestations terminées depuis plus de N jours dont le client n'a ni confirmé
 * la réception ni ouvert de litige sont considérées comme reçues, et le prestataire est payé.
 * (Remplace l'ancien liberer_escrows.php.)
 */
class LibererEscrows extends Command
{
    protected $signature = 'koudmain:liberer-escrows {--jours= : Délai en jours (par défaut : koudmain.finance.liberation_auto_jours)}';

    protected $description = 'Libère au prestataire le paiement des commandes terminées et jamais confirmées par le client.';

    public function handle(CommandeService $commandes, Suivi $suivi): int
    {
        $jours = $this->option('jours') !== null ? max(1, (int) $this->option('jours')) : null;

        $resume = $suivi->suivre('liberation-escrows', function () use ($commandes, $jours): string {
            $nombre = $commandes->libererExpirees($jours);

            return $nombre === 0 ? 'Aucun paiement à libérer' : "$nombre paiement(s) libéré(s)";
        });

        if ($resume === null) {
            $this->error('La libération des paiements a échoué (voir les journaux).');

            return self::FAILURE;
        }

        $this->info($resume.'.');

        return self::SUCCESS;
    }
}
