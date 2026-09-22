<?php

namespace Tests\Feature\Commandes;

use App\Enums\ActionCommande;
use App\Enums\ModePaiement;
use App\Enums\StatutCommande;
use App\Exceptions\OperationRefusee;
use App\Models\Commande;
use App\Models\Escrow;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommandeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCartes;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Choisir comment payer une commande : en main propre (sans séquestre), par Mobile Money ou par carte (wallet + séquestre). */
class ModePaiementCommandeTest extends TestCase
{
    use CreeDesCartes;
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

    private function formulaire(array $surcharge = []): array
    {
        return $surcharge + ['quantite' => 1, 'date' => now()->addDay()->format('Y-m-d'), 'heure' => '10:00', 'precisions' => 'Portail vert'];
    }

    private function commanderEn(ModePaiement $mode, User $client, ?User $prestataire = null, int $prix = 4000, ?int $carteId = null): Commande
    {
        return $this->service->commander($client, $this->uneOffre($prestataire, $prix), 1, $this->demain(), null, null, $carteId, $mode);
    }

    // ------------------------------------------------------------------ Le service

    public function test_le_paiement_physique_ne_touche_pas_au_wallet_et_ne_cree_aucun_sequestre(): void
    {
        $client = $this->unClient(1000); // bien moins que le prix : le wallet n'est pas concerné

        $commande = $this->commanderEn(ModePaiement::Physique, $client, prix: 4000);

        $this->assertSame(ModePaiement::Physique, $commande->mode_paiement);
        $this->assertTrue($commande->payeeEnPhysique());
        $this->assertSame(1000.0, $this->solde($client));
        $this->assertSame(0, Escrow::query()->count());
        $this->assertSame(0, WalletTransaction::query()->where('commande_id', $commande->id)->count());
    }

    public function test_le_paiement_physique_marche_meme_avec_un_wallet_vide(): void
    {
        $client = $this->unClient();

        $commande = $this->commanderEn(ModePaiement::Physique, $client);

        $this->assertSame(StatutCommande::EnAttente, $commande->statut);
    }

    public function test_mobile_money_preleve_le_wallet_et_bloque_en_sequestre(): void
    {
        $client = $this->unClient(10000);

        $commande = $this->commanderEn(ModePaiement::MobileMoney, $client, prix: 4000);

        $this->assertSame(ModePaiement::MobileMoney, $commande->mode_paiement);
        $this->assertSame(6000.0, $this->solde($client));
        $this->assertSame(Escrow::BLOQUE, Escrow::query()->where('commande_id', $commande->id)->firstOrFail()->statut);
        $this->assertNull(WalletTransaction::query()->where('commande_id', $commande->id)->value('carte_id'));
    }

    public function test_mobile_money_avec_un_solde_insuffisant_est_refuse_sans_rien_ecrire(): void
    {
        $client = $this->unClient(1000);

        try {
            $this->commanderEn(ModePaiement::MobileMoney, $client, prix: 4000);
            $this->fail('Le solde est insuffisant.');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('solde', mb_strtolower($e->getMessage()));
        }

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(1000.0, $this->solde($client));
    }

    public function test_par_carte_le_wallet_est_debite_le_sequestre_cree_et_la_carte_notee(): void
    {
        $client = $this->unClient(10000);
        $carte = $this->uneCarte($client);

        $commande = $this->commanderEn(ModePaiement::Carte, $client, prix: 4000, carteId: $carte->id);

        $this->assertSame(ModePaiement::Carte, $commande->mode_paiement);
        $this->assertSame(6000.0, $this->solde($client));
        $this->assertSame(Escrow::BLOQUE, Escrow::query()->where('commande_id', $commande->id)->firstOrFail()->statut);
        $this->assertSame($carte->id, WalletTransaction::query()->where('commande_id', $commande->id)->where('type', 'debit')->value('carte_id'));
    }

    public function test_sans_mode_precise_c_est_mobile_money(): void
    {
        $client = $this->unClient(10000);

        $commande = $this->service->commander($client, $this->uneOffre(), 1, $this->demain());

        $this->assertSame(ModePaiement::MobileMoney, $commande->mode_paiement);
        $this->assertSame(1, Escrow::query()->count());
    }

    // ------------------------------------------------------------------ Le cycle de vie d'une commande payée en main propre

    private function physiqueAcceptee(): array
    {
        $client = $this->unClient(500);
        $prestataire = $this->unPrestataire();
        $commande = $this->commanderEn(ModePaiement::Physique, $client, $prestataire, 4000);
        $this->service->agir($commande, $prestataire, ActionCommande::Accepter);

        return [$commande, $client, $prestataire];
    }

    public function test_une_commande_physique_va_jusqu_au_bout_sans_mouvement_d_argent(): void
    {
        [$commande, $client, $prestataire] = $this->physiqueAcceptee();

        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);

        $c = $commande->fresh();
        $this->assertSame(StatutCommande::Terminee, $c->statut);
        $this->assertNotNull($c->validee_client_at);
        $this->assertSame(500.0, $this->solde($client));
        $this->assertSame(0.0, $this->solde($prestataire));
        $this->assertSame(0, Escrow::query()->count());
        $this->assertSame(0, WalletTransaction::query()->where('commande_id', $commande->id)->count());
    }

    public function test_annuler_une_commande_physique_ne_rembourse_rien(): void
    {
        [$commande, $client] = $this->physiqueAcceptee();

        $this->service->agir($commande, $client, ActionCommande::Annuler, 'Changement de programme');

        $this->assertSame(StatutCommande::Annulee, $commande->fresh()->statut);
        $this->assertSame(500.0, $this->solde($client), 'pas de remboursement fantôme');
        $this->assertSame(0, WalletTransaction::query()->where('commande_id', $commande->id)->count());
    }

    public function test_le_client_peut_noter_une_commande_physique_terminee(): void
    {
        [$commande, $client, $prestataire] = $this->physiqueAcceptee();
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->service->agir($commande, $client, ActionCommande::ConfirmerReception);
        $prestation = $commande->prestations()->firstOrFail();

        $this->actingAs($client)->post(route('client.commandes.avis', $commande), ['prestation_id' => $prestation->id, 'note' => 5, 'commentaire' => 'Très bien'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('avis', ['commande_id' => $commande->id, 'note' => 5]);
    }

    public function test_litige_et_arbitrage_d_une_commande_physique_ne_bougent_aucun_argent(): void
    {
        [$commande, $client, $prestataire] = $this->physiqueAcceptee();
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->service->agir($commande, $client, ActionCommande::OuvrirLitige, 'Le prestataire est parti avant la fin.');
        $admin = User::factory()->create(['est_admin' => true]);

        $this->service->arbitrer($commande, $admin, payerLePrestataire: true, note: 'Photos à l\'appui');

        $this->assertSame(StatutCommande::Terminee, $commande->fresh()->statut);
        $this->assertSame(500.0, $this->solde($client));
        $this->assertSame(0.0, $this->solde($prestataire));
        $this->assertSame(0, Escrow::query()->count());
    }

    public function test_la_liberation_automatique_d_une_commande_physique_ne_verse_rien(): void
    {
        [$commande, $client, $prestataire] = $this->physiqueAcceptee();
        $this->service->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->service->agir($commande, $prestataire, ActionCommande::Terminer);

        \Carbon\CarbonImmutable::setTestNow(now()->addDays(10));
        \Illuminate\Support\Carbon::setTestNow(now()->addDays(10));
        $this->service->libererExpirees();

        $this->assertNotNull($commande->fresh()->validee_client_at);
        $this->assertSame(0.0, $this->solde($prestataire));
        $this->assertSame(500.0, $this->solde($client));
    }

    // ------------------------------------------------------------------ Le formulaire

    public function test_la_page_de_commande_propose_les_trois_modes_et_les_cartes(): void
    {
        $client = $this->unClient(12000);
        $offre = $this->uneOffre(prix: 5000);
        $this->uneCarte($client, ['libelle' => 'Ma Visa']);

        $page = $this->actingAs($client)->get(route('client.commander', $offre))->assertOk();

        $page->assertSee('data-island="CommandeFormulaire"', false)->assertSee('Paiement physique')->assertSee('Mobile Money')->assertSee('Carte bancaire');
        $page->assertSee('name="mode_paiement"', false)->assertSee('Ma Visa');
    }

    public function test_le_formulaire_commande_en_paiement_physique(): void
    {
        $client = $this->unClient(0);
        $offre = $this->uneOffre(prix: 5000);

        $reponse = $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'physique']));

        $commande = Commande::query()->firstOrFail();
        $reponse->assertRedirect(route('client.commandes.voir', $commande))->assertSessionHas('succes');
        $this->assertSame(ModePaiement::Physique, $commande->mode_paiement);
        $this->assertSame(0, Escrow::query()->count());
        $this->assertStringContainsString('main propre', session('succes'));
        $this->assertStringNotContainsString('séquestre', session('succes'));
    }

    public function test_le_formulaire_commande_par_mobile_money_ou_sans_choix(): void
    {
        $client = $this->unClient(20000);
        $offre = $this->uneOffre(prix: 5000);
        $this->actingAs($client);

        $this->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'mobile_money']))->assertSessionHas('succes');
        $this->post(route('client.commander.envoyer', $offre), $this->formulaire(['heure' => '11:00']))->assertSessionHas('succes');

        $this->assertSame([ModePaiement::MobileMoney, ModePaiement::MobileMoney], Commande::query()->orderBy('id')->get()->pluck('mode_paiement')->all());
        $this->assertSame(10000.0, $this->solde($client));
        $this->assertSame(2, Escrow::query()->count());
    }

    public function test_le_formulaire_commande_par_carte(): void
    {
        $client = $this->unClient(20000);
        $offre = $this->uneOffre(prix: 5000);
        $carte = $this->uneCarte($client);

        $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'carte', 'carte_id' => $carte->id]))->assertSessionHas('succes');

        $commande = Commande::query()->firstOrFail();
        $this->assertSame(ModePaiement::Carte, $commande->mode_paiement);
        $this->assertSame(15000.0, $this->solde($client));
        $this->assertSame($carte->id, WalletTransaction::query()->where('commande_id', $commande->id)->where('type', 'debit')->value('carte_id'));
    }

    public function test_par_carte_la_carte_est_obligatoire_et_un_mode_inconnu_est_refuse(): void
    {
        $client = $this->unClient(20000);
        $offre = $this->uneOffre(prix: 5000);
        $this->actingAs($client);

        $this->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'carte']))->assertSessionHasErrors(['carte_id']);
        $this->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'bitcoin']))->assertSessionHasErrors(['mode_paiement']);

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(20000.0, $this->solde($client));
    }

    public function test_par_carte_sans_carte_enregistree_le_client_est_renvoye_vers_l_ajout(): void
    {
        $client = $this->unClient(20000);
        $offre = $this->uneOffre(prix: 5000);

        $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'carte', 'carte_id' => 999]))->assertSessionHas('erreur');

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(20000.0, $this->solde($client));
    }

    public function test_commander_avec_une_carte_gelee_est_refuse_et_la_page_le_dit(): void
    {
        $client = $this->unClient(50000);
        $offre = $this->uneOffre(prix: 5000);
        $gelee = $this->uneCarte($client, ['libelle' => 'Gelée']);
        $this->actingAs($client)->patch(route('client.wallet.cartes.geler', $gelee->id));

        $this->get(route('client.commander', $offre))->assertOk()->assertSee('Gelée')->assertSee('gelée');
        $this->post(route('client.commander.envoyer', $offre), $this->formulaire(['mode_paiement' => 'carte', 'carte_id' => $gelee->id]))->assertSessionHas('erreur');

        $this->assertSame(50000.0, $this->solde($client));
        $this->assertSame(0, Commande::query()->count());
    }

    // ------------------------------------------------------------------ L'affichage

    public function test_les_pages_de_commande_disent_comment_elle_est_payee(): void
    {
        $client = $this->unClient(20000);
        $prestataire = $this->unPrestataire();
        $physique = $this->commanderEn(ModePaiement::Physique, $client, $prestataire);
        $mobile = $this->commanderEn(ModePaiement::MobileMoney, $client, $prestataire);

        $this->actingAs($client)->get(route('client.commandes.voir', $physique))->assertOk()
            ->assertSee('Mode de paiement')->assertSee('Paiement physique')->assertSee('en main propre')->assertDontSee('Votre argent est bloqué en séquestre');
        $this->get(route('client.commandes.voir', $mobile))->assertOk()->assertSee('Mobile Money')->assertSee('Votre argent est bloqué en séquestre');

        $this->get(route('client.commandes'))->assertOk()->assertSee('Espèces')->assertSee('Mobile Money');
        $this->actingAs($prestataire)->get(route('prestataire.commandes.voir', $physique))->assertOk()->assertSee('Paiement physique')->assertSee('à régler en main propre');
    }

    public function test_un_message_de_succes_ne_parle_pas_de_remboursement_pour_une_commande_physique(): void
    {
        $client = $this->unClient();
        $commande = $this->commanderEn(ModePaiement::Physique, $client);

        $this->actingAs($client)->post(route('client.commandes.agir', [$commande, 'annuler']), ['motif' => 'Plus besoin'])->assertSessionHas('succes', 'Commande annulée.');
    }

    public function test_les_modes_ont_un_libelle_une_icone_et_savent_s_ils_passent_par_le_wallet(): void
    {
        $this->assertSame('Paiement physique', ModePaiement::Physique->libelle());
        $this->assertSame('Espèces', ModePaiement::Physique->court());
        $this->assertFalse(ModePaiement::Physique->passeParLeWallet());
        $this->assertTrue(ModePaiement::MobileMoney->passeParLeWallet());
        $this->assertTrue(ModePaiement::Carte->passeParLeWallet());

        foreach (ModePaiement::cases() as $mode) {
            $this->assertTrue(\App\Support\Icones::existe($mode->icone()), "l'icône de {$mode->value} existe");
        }
    }
}
