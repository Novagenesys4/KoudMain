<?php

namespace App\Services\Surveillance;

use App\Events\CommandeChangee;
use App\Events\CommandePassee;
use App\Events\RetraitDemande;
use App\Events\RetraitTraite;
use App\Support\Journal;
use Throwable;

/**
 * Écoute les événements de l'application et écrit une ligne de journal pour chacun de ceux qui touchent à l'argent ou à la
 * confiance : commande passée, acceptée, terminée, en litige, paiement libéré ou remboursé, retrait demandé puis traité.
 * Les lignes ne contiennent que des identifiants, des montants et des statuts (voir App\Support\Journal pour le masquage).
 * N'utilise que les colonnes déjà chargées : aucune requête en plus, et jamais d'erreur pour l'utilisateur.
 */
class Audit
{
    public function commandePassee(CommandePassee $e): void
    {
        $this->ecrire(fn () => Journal::info('commande.passee', [
            'commande' => $e->commande->id,
            'client' => $e->commande->client_id,
            'prestataire' => $e->commande->prestataire_id,
            'montant' => (string) $e->commande->montant_total,
        ]));
    }

    public function commandeChangee(CommandeChangee $e): void
    {
        // Les événements qui bougent de l'argent ou signalent un différend sont mis en avant : ce sont ceux qu'on recherche.
        $sensible = in_array($e->evenement, ['ouvrir_litige', 'confirmer_reception', 'liberation_auto', 'arbitrage_liberer', 'arbitrage_rembourser'], true);
        $ligne = fn () => [
            'commande' => $e->commande->id,
            'evenement' => $e->evenement,
            'statut' => $e->commande->statut->value,
            'montant' => (string) $e->commande->montant_total,
            'acteur' => $e->acteur?->id,
        ];

        $this->ecrire(fn () => $sensible
            ? Journal::info('commande.'.$e->evenement, $ligne())
            : Journal::info('commande.changee', $ligne()));
    }

    public function retraitDemande(RetraitDemande $e): void
    {
        $this->ecrire(fn () => Journal::info('retrait.demande', [
            'retrait' => $e->retrait->id,
            'prestataire' => $e->retrait->user_id,
            'montant' => (string) $e->retrait->montant,
            'methode' => $e->retrait->methode,
            'destination' => $e->retrait->destination, // réduite aux 4 derniers chiffres par Journal
        ]));
    }

    public function retraitTraite(RetraitTraite $e): void
    {
        $this->ecrire(fn () => Journal::info('retrait.'.($e->retrait->statut === 'effectue' ? 'effectue' : 'refuse'), [
            'retrait' => $e->retrait->id,
            'prestataire' => $e->retrait->user_id,
            'montant' => (string) $e->retrait->montant,
            'administrateur' => $e->retrait->traite_par,
        ]));
    }

    private function ecrire(callable $ecriture): void
    {
        try {
            $ecriture();
        } catch (Throwable) {
            // Le journal est une commodité : il ne doit jamais empêcher une commande ou un retrait.
        }
    }
}
