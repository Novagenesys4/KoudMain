<?php

namespace Tests\Concerns;

use App\Models\Commande;
use App\Models\Prestation;
use App\Models\User;
use App\Services\CommandeService;
use App\Services\WalletService;
use Carbon\CarbonImmutable;

/** Petits constructeurs pour les tests d'argent et de commandes. Le temps est figé : lundi 21 septembre 2026, 09:00. */
trait CreeDesCommandes
{
    use CreeDesPrestations;

    protected function figerLeTemps(): CarbonImmutable
    {
        $maintenant = CarbonImmutable::parse('2026-09-21 09:00:00');
        CarbonImmutable::setTestNow($maintenant);
        \Illuminate\Support\Carbon::setTestNow($maintenant);

        return $maintenant;
    }

    protected function unClient(int|float $solde = 0): User
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        if ($solde > 0) {
            app(WalletService::class)->mouvement($client, 'credit', $solde, 'Solde de départ (test)');
        }

        return $client;
    }

    protected function uneOffre(?User $prestataire = null, int $prix = 5000, ?int $duree = 60): Prestation
    {
        $prestataire ??= $this->unPrestataire();

        return Prestation::factory()->for($prestataire, 'prestataire')->create(['prix' => $prix, 'duree_minutes' => $duree, 'service_id' => $this->unService()->id]);
    }

    /** Demain 10:00 : dans les horaires par défaut, assez loin de « maintenant ». */
    protected function demain(string $heure = '10:00'): CarbonImmutable
    {
        return CarbonImmutable::now()->addDay()->setTimeFromTimeString($heure);
    }

    protected function commander(User $client, Prestation $offre, int $quantite = 1, ?CarbonImmutable $debut = null, ?string $precisions = null): Commande
    {
        return app(CommandeService::class)->commander($client, $offre, $quantite, $debut ?? $this->demain(), $precisions);
    }

    protected function solde(User $utilisateur): float
    {
        return app(WalletService::class)->solde($utilisateur);
    }
}
