<?php

namespace Tests\Feature\Commandes;

use App\Enums\ActionCommande;
use App\Enums\StatutCommande;
use App\Events\CommandeChangee;
use App\Events\CommandePassee;
use App\Exceptions\OperationRefusee;
use App\Exceptions\SoldeInsuffisant;
use App\Models\Commande;
use App\Models\Escrow;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommandeService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class CommandeServiceTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private CommandeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        $this->service = app(CommandeService::class);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Commander

    public function test_commander_bloque_l_argent_dans_le_sequestre(): void
    {
        Event::fake([CommandePassee::class]);
        $client = $this->unClient(20000);
        $offre = $this->uneOffre(prix: 6000);

        $commande = $this->commander($client, $offre, quantite: 2, precisions: 'Portail bleu, 2e étage');

        $this->assertSame(StatutCommande::EnAttente, $commande->statut);
        $this->assertSame($offre->prestataire_id, $commande->prestataire_id);
        $this->assertSame('12000.00', $commande->montant_total);
        $this->assertSame(8000.0, $this->solde($client));

        $escrow = Escrow::query()->where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame(Escrow::BLOQUE, $escrow->statut);
        $this->assertSame('12000.00', $escrow->montant);

        $ligne = $commande->prestations()->firstOrFail();
        $this->assertSame(2, $ligne->pivot->quantite);
        $this->assertSame('6000.00', $ligne->pivot->prix_unitaire);

        $this->assertSame(120, $commande->duree_minutes);
        $this->assertSame('Portail bleu, 2e étage', $commande->precisions);
        Event::assertDispatched(CommandePassee::class);
    }

    public function test_un_solde_insuffisant_n_ecrit_rien(): void
    {
        $client = $this->unClient(3000);
        $offre = $this->uneOffre(prix: 5000);

        try {
            $this->commander($client, $offre);
            $this->fail('SoldeInsuffisant attendu');
        } catch (SoldeInsuffisant $e) {
            $this->assertStringContainsString('Rechargez votre wallet', $e->getMessage());
        }

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(0, Escrow::query()->count());
        $this->assertSame(3000.0, $this->solde($client));
        $this->assertSame(1, WalletTransaction::query()->count());
    }

    public function test_les_commandes_interdites(): void
    {
        $client = $this->unClient(50000);
        $offre = $this->uneOffre();
        $refus = fn (callable $appel) => $this->assertThrows($appel, OperationRefusee::class);

        $refus(fn () => $this->commander($client, $offre, quantite: 0));
        $refus(fn () => $this->commander($client, $offre, quantite: 21));
        $refus(fn () => $this->service->commander($client, $offre, 1, null));                                   // pas de créneau
        $double = User::factory()->create(['est_client' => true, 'est_prestataire' => true, 'quartier_id' => $client->quartier_id]); // à la fois client et prestataire
        app(WalletService::class)->mouvement($double, 'credit', 50000, 'Départ');
        $refus(fn () => $this->commander($double, $this->uneOffre($double)));                                    // sa propre prestation

        $masquee = $this->uneOffre();
        $masquee->forceFill(['est_active' => false])->save();
        $refus(fn () => $this->commander($client, $masquee));

        $suspendu = $this->unPrestataire();
        $suspendu->forceFill(['est_valide' => false])->save();
        $refus(fn () => $this->commander($client, $this->uneOffre($suspendu)));

        $refus(fn () => $this->commander($this->unPrestataire(), $offre)); // un prestataire n'est pas un client

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(50000.0, $this->solde($client));
    }

    public function test_les_creneaux_invalides_sont_refuses(): void
    {
        $client = $this->unClient(50000);
        $offre = $this->uneOffre();
        $refus = fn (string $heure, string $motif) => $this->assertThrows(
            fn () => $this->commander($client, $offre, debut: $this->demain($heure)),
            OperationRefusee::class,
            $motif,
        );

        $refus('03:00', 'pas disponible');                       // hors de la plage 07 h - 21 h
        $refus('10:15', 'heure ronde');
        $this->assertThrows(fn () => $this->service->commander($client, $offre, 1, now()->addHour()), OperationRefusee::class, 'trop proche');
        $this->assertThrows(fn () => $this->service->commander($client, $offre, 1, now()->addDays(30)->setTime(10, 0)), OperationRefusee::class, 'jours à l\'avance');
    }

    // ---------------------------------------------------------------- Workflow

    public function test_le_parcours_complet_paie_le_prestataire(): void
    {
        Event::fake([CommandeChangee::class]);
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $offre = $this->uneOffre($prestataire, 4000);
        $commande = $this->commander($client, $offre);

        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->assertSame(StatutCommande::Acceptee, $commande->fresh()->statut);
        $this->assertNotNull($commande->fresh()->acceptee_at);

        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $termineeLe = $commande->fresh()->terminee_at;
        $this->assertSame(0.0, $this->solde($prestataire), 'Pas payé tant que le client n\'a pas confirmé.');

        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);

        $final = $commande->fresh();
        $this->assertSame(StatutCommande::Terminee, $final->statut);
        $this->assertNotNull($final->validee_client_at);
        $this->assertEquals($termineeLe, $final->terminee_at, 'La confirmation ne réécrit pas la date de fin.');
        $this->assertSame(4000.0, $this->solde($prestataire));
        $this->assertSame(6000.0, $this->solde($client));
        $this->assertSame(Escrow::LIBERE, $final->escrow->statut);
        $this->assertNotNull($final->escrow->libere_at);
        Event::assertDispatchedTimes(CommandeChangee::class, 4);
    }

    public function test_annuler_rembourse_dans_la_meme_operation(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));

        $this->service->agir($commande, $client, ActionCommande::Annuler, 'Imprévu');

        $c = $commande->fresh();
        $this->assertSame(StatutCommande::Annulee, $c->statut);
        $this->assertNotNull($c->annulee_at);
        $this->assertSame('Annulée par le client : Imprévu', $c->motif_annulation);
        $this->assertSame(10000.0, $this->solde($client));
        $this->assertSame(Escrow::REMBOURSE, $c->escrow->statut);
        $this->assertSame(0.0, $this->solde($prestataire));
    }

    public function test_le_prestataire_peut_aussi_annuler_une_commande_acceptee(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));
        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);

        $this->service->agir($commande, $prestataire, ActionCommande::Annuler);

        $this->assertSame(10000.0, $this->solde($client));
        $this->assertStringContainsString('le prestataire', $commande->fresh()->motif_annulation);
    }

    public function test_on_ne_peut_plus_annuler_une_commande_en_cours(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));
        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);

        $this->assertThrows(fn () => $this->service->agir($commande, $client, ActionCommande::Annuler), OperationRefusee::class, 'n\'est plus possible');
        $this->assertSame(6000.0, $this->solde($client));
    }

    public function test_chacun_ne_fait_que_ce_qui_le_concerne(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $etranger = $this->unPrestataire();
        $admin = User::factory()->create(['est_admin' => true]);
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));

        foreach ([$client, $etranger, $admin] as $intrus) {
            $this->assertThrows(fn () => $this->service->agir($commande, $intrus, ActionCommande::Accepter), OperationRefusee::class, 'Vous ne pouvez pas');
        }

        $this->assertThrows(fn () => $this->service->agir($commande, $etranger, ActionCommande::Annuler), OperationRefusee::class);
        $this->assertSame(StatutCommande::EnAttente, $commande->fresh()->statut);

        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->assertThrows(fn () => $this->service->agir($commande, $prestataire, ActionCommande::ConfirmerReception), OperationRefusee::class);
    }

    public function test_un_double_clic_ne_joue_pas_deux_fois_la_meme_transition(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));
        $obsolete = Commande::query()->findOrFail($commande->id); // copie chargée AVANT le premier clic

        $this->service->agir($commande, $client, ActionCommande::Annuler);
        $this->assertThrows(fn () => $this->service->agir($obsolete, $client, ActionCommande::Annuler), OperationRefusee::class);

        $this->assertSame(10000.0, $this->solde($client), 'Remboursé une seule fois.');
        $this->assertSame(1, WalletTransaction::query()->where('libelle', 'like', 'Remboursement%')->count());
    }

    public function test_la_confirmation_de_reception_ne_paie_qu_une_fois(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $etape) {
            $this->service->agir($commande, $prestataire, $etape);
        }

        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);
        $this->assertThrows(fn () => $this->service->agir($commande, $client, ActionCommande::ConfirmerReception), OperationRefusee::class);

        $this->assertSame(4000.0, $this->solde($prestataire));
    }

    public function test_deux_commandes_acceptees_ne_se_chevauchent_pas(): void
    {
        $prestataire = $this->unPrestataire();
        $offre = $this->uneOffre($prestataire, 4000, 60);
        $a = $this->commander($this->unClient(10000), $offre, debut: $this->demain('10:00'));
        $b = $this->commander($this->unClient(10000), $offre, debut: $this->demain('10:30')); // en attente : ne réserve rien

        $this->service->agir($a, $prestataire, ActionCommande::Accepter);

        $this->assertThrows(fn () => $this->service->agir($b, $prestataire, ActionCommande::Accepter), OperationRefusee::class, 'déjà pris');
        $this->assertSame(StatutCommande::EnAttente, $b->fresh()->statut);
        // Et le créneau n'est plus proposé à un troisième client.
        $this->assertThrows(fn () => $this->commander($this->unClient(10000), $offre, debut: $this->demain('10:30')), OperationRefusee::class, 'déjà pris');
        // 11:00 est libre.
        $this->assertNotNull($this->commander($this->unClient(10000), $offre, debut: $this->demain('11:00'))->id);
    }

    public function test_on_n_accepte_pas_un_creneau_deja_passe(): void
    {
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($this->unClient(10000), $this->uneOffre($prestataire, 4000));
        \Illuminate\Support\Carbon::setTestNow(now()->addDays(3));

        $this->assertThrows(fn () => $this->service->agir($commande, $prestataire, ActionCommande::Accepter), OperationRefusee::class, 'déjà passé');
    }

    // ------------------------------------------------------------------ Litige

    private function commandeEnCours(int $prix = 4000): array
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, $prix));
        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);

        return [$commande, $client, $prestataire];
    }

    public function test_un_litige_exige_un_motif_et_garde_l_argent_bloque(): void
    {
        [$commande, $client] = $this->commandeEnCours();

        $this->assertThrows(fn () => $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'nul'), OperationRefusee::class, 'Décrivez le problème');
        $this->assertSame(StatutCommande::EnCours, $commande->fresh()->statut);

        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Le prestataire est parti avant la fin.');

        $c = $commande->fresh();
        $this->assertSame(StatutCommande::Litige, $c->statut);
        $this->assertSame('Le prestataire est parti avant la fin.', $c->motif_litige);
        $this->assertSame(Escrow::LITIGE, $c->escrow->statut);
        $this->assertSame(6000.0, $this->solde($client));
    }

    public function test_l_arbitrage_en_faveur_du_prestataire_le_paie(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Travail non conforme au devis.');
        $admin = User::factory()->create(['est_admin' => true]);

        $this->service->arbitrer($commande, $admin, payerLePrestataire: true, note: 'Photos à l\'appui');

        $c = $commande->fresh();
        $this->assertSame(StatutCommande::Terminee, $c->statut);
        $this->assertNotNull($c->validee_client_at);
        $this->assertSame(4000.0, $this->solde($prestataire));
        $this->assertSame(6000.0, $this->solde($client));
        $this->assertSame(Escrow::LIBERE, $c->escrow->statut);
    }

    public function test_l_arbitrage_en_faveur_du_client_le_rembourse(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Prestation jamais réalisée.');
        $admin = User::factory()->create(['est_admin' => true]);

        $this->service->arbitrer($commande, $admin, payerLePrestataire: false, note: 'Aucune preuve de passage');

        $c = $commande->fresh();
        $this->assertSame(StatutCommande::Annulee, $c->statut);
        $this->assertStringContainsString('Aucune preuve de passage', $c->motif_annulation);
        $this->assertSame(10000.0, $this->solde($client));
        $this->assertSame(0.0, $this->solde($prestataire));
        $this->assertSame(Escrow::REMBOURSE, $c->escrow->statut);
    }

    public function test_l_arbitrage_est_reserve_aux_administrateurs_et_aux_litiges(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $admin = User::factory()->create(['est_admin' => true]);

        $this->assertThrows(fn () => $this->service->arbitrer($commande, $admin, true), OperationRefusee::class, 'pas en litige');

        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Un problème sérieux.');
        $this->assertThrows(fn () => $this->service->arbitrer($commande, $client, false), OperationRefusee::class, 'administrateurs');
        $this->assertThrows(fn () => $this->service->arbitrer($commande, $prestataire, true), OperationRefusee::class, 'administrateurs');

        $this->service->arbitrer($commande, $admin, true);
        $this->assertThrows(fn () => $this->service->arbitrer($commande, $admin, false), OperationRefusee::class, 'pas en litige');
        $this->assertSame(4000.0, $this->solde($prestataire));
    }

    public function test_le_client_peut_contester_une_prestation_terminee_avant_de_confirmer(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);

        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Le travail n\'est pas fini du tout.');

        $this->assertSame(StatutCommande::Litige, $commande->fresh()->statut);
        $this->assertSame(Escrow::LITIGE, $commande->fresh()->escrow->statut);
        $this->assertSame(0.0, $this->solde($prestataire));
    }

    public function test_apres_la_confirmation_il_n_est_plus_possible_de_contester(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);

        $this->assertThrows(fn () => $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Trop tard pour se plaindre.'), OperationRefusee::class, 'déjà confirmé');
        $this->assertSame(4000.0, $this->solde($prestataire));
    }

    // ------------------------------------------------- Libération automatique

    public function test_le_paiement_est_libere_automatiquement_apres_trois_jours(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);

        $this->assertSame(0, $this->service->libererExpirees(), 'Trop tôt : le client a encore le temps.');

        \Illuminate\Support\Carbon::setTestNow(now()->addDays(3)->addMinute());
        $this->assertSame(1, $this->service->libererExpirees());
        $this->assertSame(4000.0, $this->solde($prestataire));
        $this->assertNotNull($commande->fresh()->validee_client_at);

        $this->assertSame(0, $this->service->libererExpirees(), 'Idempotent : pas de second paiement.');
        $this->assertSame(4000.0, $this->solde($prestataire));
    }

    public function test_un_litige_n_est_jamais_libere_automatiquement(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours();
        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Un problème sérieux.');

        \Illuminate\Support\Carbon::setTestNow(now()->addDays(30));

        $this->assertSame(0, $this->service->libererExpirees());
        $this->assertSame(0.0, $this->solde($prestataire));
    }

    public function test_l_argent_total_est_conserve_a_chaque_etape(): void
    {
        [$commande, $client, $prestataire] = $this->commandeEnCours(4000);
        $total = fn () => $this->solde($client) + $this->solde($prestataire) + (float) Escrow::query()->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])->sum('montant');

        $this->assertSame(10000.0, $total());
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->assertSame(10000.0, $total());
        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);
        $this->assertSame(10000.0, $total());
    }

    public function test_les_actions_proposees_suivent_l_etat_et_le_role(): void
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 4000));

        $noms = fn (Commande $c, User $u) => array_map(fn (ActionCommande $a) => $a->value, ActionCommande::possibles($c->fresh(), $u));

        $this->assertSame(['accepter', 'annuler'], $noms($commande, $prestataire));
        $this->assertSame(['annuler'], $noms($commande, $client));

        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->assertSame(['terminer'], $noms($commande, $prestataire));
        $this->assertSame(['ouvrir_litige'], $noms($commande, $client));

        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->assertSame(['ouvrir_litige', 'confirmer_reception'], $noms($commande, $client));

        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);
        $this->assertSame([], $noms($commande, $client));
    }
}
