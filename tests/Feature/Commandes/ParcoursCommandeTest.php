<?php

namespace Tests\Feature\Commandes;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\User;
use App\Services\CommandeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Le parcours HTTP : commander, suivre, agir, annuler, contester, arbitrer. La logique d'argent est testée dans CommandeServiceTest. */
class ParcoursCommandeTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
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

    // ---------------------------------------------------------------- Commander

    public function test_la_page_de_commande_affiche_le_solde_et_le_formulaire(): void
    {
        $client = $this->unClient(12000);
        $offre = $this->uneOffre(prix: 5000);

        $this->actingAs($client)->get(route('client.commander', $offre))->assertOk()
            ->assertSee($offre->titre)->assertSee('Réserver')->assertSee('data-island="CommandeFormulaire"', false)
            ->assertSee('name="heure"', false);   // le repli sans JavaScript
    }

    public function test_un_client_commande_et_l_argent_est_bloque(): void
    {
        $client = $this->unClient(12000);
        $offre = $this->uneOffre(prix: 5000);

        $reponse = $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire(['quantite' => 2]));

        $commande = Commande::query()->firstOrFail();
        $reponse->assertRedirect(route('client.commandes.voir', $commande))->assertSessionHas('succes');
        $this->assertSame(2000.0, $this->solde($client));
        $this->assertSame(StatutCommande::EnAttente, $commande->statut);
        $this->actingAs($client)->get(route('client.commandes.voir', $commande))->assertOk()->assertSee('Portail vert')->assertSee('En attente');
    }

    public function test_solde_insuffisant_renvoie_au_formulaire_avec_un_message(): void
    {
        $client = $this->unClient(1000);
        $offre = $this->uneOffre(prix: 5000);

        $this->actingAs($client)->from(route('client.commander', $offre))->post(route('client.commander.envoyer', $offre), $this->formulaire())
            ->assertRedirect(route('client.commander', $offre))->assertSessionHas('erreur');
        $this->assertSame(0, Commande::query()->count());
    }

    public function test_le_formulaire_est_valide_cote_serveur(): void
    {
        $client = $this->unClient(50000);
        $offre = $this->uneOffre();

        foreach ([['quantite' => 0], ['quantite' => 99], ['date' => 'demain'], ['heure' => '25:00'], ['precisions' => str_repeat('a', 501)]] as $mauvais) {
            $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire($mauvais))->assertSessionHasErrors();
        }

        $this->assertSame(0, Commande::query()->count());
    }

    public function test_le_prix_et_le_prestataire_ne_viennent_jamais_du_navigateur(): void
    {
        $client = $this->unClient(50000);
        $offre = $this->uneOffre(prix: 5000);
        $autre = $this->unPrestataire();

        $this->actingAs($client)->post(route('client.commander.envoyer', $offre), $this->formulaire(['montant_total' => 1, 'prix' => 1, 'prestataire_id' => $autre->id, 'statut' => 'terminee']));

        $commande = Commande::query()->firstOrFail();
        $this->assertSame('5000.00', $commande->montant_total);
        $this->assertSame($offre->prestataire_id, $commande->prestataire_id);
        $this->assertSame(StatutCommande::EnAttente, $commande->statut);
    }

    public function test_une_prestation_masquee_ne_se_commande_pas(): void
    {
        $offre = $this->uneOffre();
        $offre->forceFill(['est_active' => false])->save();

        $this->actingAs($this->unClient(9000))->get(route('client.commander', $offre))->assertNotFound();
    }

    public function test_seul_un_client_commande(): void
    {
        $offre = $this->uneOffre();

        $this->get(route('client.commander', $offre))->assertRedirect(route('connexion'));
        $this->actingAs($this->unPrestataire())->get(route('client.commander', $offre))->assertForbidden();
    }

    public function test_la_page_publique_propose_de_commander_et_montre_les_disponibilites(): void
    {
        $prestataire = $this->unPrestataire();
        app(\App\Services\DisponibiliteService::class)->enregistrer($prestataire, [2 => [['08:00', '12:00']]]);
        $offre = $this->uneOffre($prestataire);

        $this->actingAs($this->unClient())->get(route('prestations.voir', $offre))->assertOk()
            ->assertSee('Disponibilités')->assertSee('08:00 – 12:00')->assertSee('Prochain créneau libre')->assertSee('mardi 22 septembre')
            ->assertSee(route('client.commander', $offre), false);
    }

    // ---------------------------------------------------------- Suivi et actions

    private function uneCommandeEnAttente(int $prix = 4000): array
    {
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, $prix));

        return [$commande, $client, $prestataire];
    }

    public function test_chacun_ne_voit_que_ses_commandes(): void
    {
        [$commande, $client, $prestataire] = $this->uneCommandeEnAttente();
        $etranger = $this->unClient();
        $autrePrestataire = $this->unPrestataire();

        $this->actingAs($client)->get(route('client.commandes'))->assertOk()->assertSee('n° '.$commande->id);
        $this->actingAs($prestataire)->get(route('prestataire.commandes'))->assertOk()->assertSee('n° '.$commande->id);
        $this->actingAs($etranger)->get(route('client.commandes'))->assertOk()->assertDontSee('n° '.$commande->id);

        $this->actingAs($etranger)->get(route('client.commandes.voir', $commande))->assertNotFound();
        $this->actingAs($autrePrestataire)->get(route('prestataire.commandes.voir', $commande))->assertNotFound();
        $this->actingAs($etranger)->post(route('client.commandes.agir', [$commande, 'annuler']))->assertNotFound();
        $this->assertSame(StatutCommande::EnAttente, $commande->fresh()->statut);
    }

    public function test_le_prestataire_accepte_puis_le_client_confirme(): void
    {
        [$commande, $client, $prestataire] = $this->uneCommandeEnAttente();

        $this->actingAs($prestataire)->post(route('prestataire.commandes.agir', [$commande, 'accepter']))->assertRedirect(route('prestataire.commandes.voir', $commande))->assertSessionHas('succes');
        $this->post(route('prestataire.commandes.agir', [$commande, 'demarrer']));
        $this->post(route('prestataire.commandes.agir', [$commande, 'terminer']));
        $this->assertSame(StatutCommande::Terminee, $commande->fresh()->statut);

        $this->actingAs($client)->get(route('client.commandes.voir', $commande))->assertSee('Confirmer la réception')->assertSee('Signaler un problème');
        $this->post(route('client.commandes.agir', [$commande, 'confirmer_reception']))->assertSessionHas('succes');

        $this->assertSame(4000.0, $this->solde($prestataire));
        $this->actingAs($prestataire)->get(route('prestataire.commandes.voir', $commande))->assertSee('Versé au prestataire');
    }

    public function test_une_action_impossible_affiche_l_erreur_sans_rien_changer(): void
    {
        [$commande, $client] = $this->uneCommandeEnAttente();

        $this->actingAs($client)->post(route('client.commandes.agir', [$commande, 'confirmer_reception']))->assertSessionHas('erreur');
        $this->post(route('client.commandes.agir', [$commande, 'nimporte_quoi']))->assertNotFound();
        $this->assertSame(StatutCommande::EnAttente, $commande->fresh()->statut);
    }

    public function test_le_client_annule_avec_un_motif_et_est_rembourse(): void
    {
        [$commande, $client] = $this->uneCommandeEnAttente();

        $this->actingAs($client)->post(route('client.commandes.agir', [$commande, 'annuler']), ['motif' => 'Changement de programme'])->assertSessionHas('succes');

        $this->assertSame(10000.0, $this->solde($client));
        $this->actingAs($client)->get(route('client.commandes.voir', $commande))->assertSee('Changement de programme')->assertSee('Remboursé');
    }

    public function test_le_numero_du_telephone_n_est_partage_qu_apres_acceptation(): void
    {
        [$commande, $client, $prestataire] = $this->uneCommandeEnAttente();
        $numero = $prestataire->telephone;

        $this->actingAs($client)->get(route('client.commandes.voir', $commande))->assertDontSee($numero);

        app(CommandeService::class)->agir($commande, $prestataire, \App\Enums\ActionCommande::Accepter);
        $this->actingAs($client)->get(route('client.commandes.voir', $commande))->assertSee($numero);
    }

    public function test_les_filtres_de_statut_de_la_liste(): void
    {
        [$commande, $client] = $this->uneCommandeEnAttente();

        $this->actingAs($client)->get(route('client.commandes', ['statut' => 'terminee']))->assertOk()->assertDontSee('n° '.$commande->id)->assertSee('Aucune commande « terminée »');
        $this->get(route('client.commandes', ['statut' => 'en_attente']))->assertSee('n° '.$commande->id);
    }

    // ----------------------------------------------------------------- Arbitrage

    public function test_l_administrateur_tranche_un_litige(): void
    {
        [$commande, $client, $prestataire] = $this->uneCommandeEnAttente();
        $service = app(CommandeService::class);
        $service->agir($commande, $prestataire, \App\Enums\ActionCommande::Accepter);
        $service->agir($commande, $prestataire, \App\Enums\ActionCommande::Demarrer);
        $this->actingAs($client)->post(route('client.commandes.agir', [$commande, 'ouvrir_litige']), ['motif' => 'Le prestataire n\'est jamais venu.'])->assertSessionHas('succes');
        $admin = User::factory()->create(['est_admin' => true, 'quartier_id' => $client->quartier_id]);

        $this->actingAs($admin)->get(route('admin.commandes.voir', $commande))->assertOk()->assertSee('Trancher ce litige')->assertSee('jamais venu');
        $this->actingAs($admin)->get(route('admin.tableau-de-bord'))->assertSee('Litiges à arbitrer');

        $this->post(route('admin.commandes.arbitrer', $commande), [])->assertSessionHasErrors('decision');
        $this->post(route('admin.commandes.arbitrer', $commande), ['decision' => 'client', 'note' => 'Aucune trace'])->assertSessionHas('succes');

        $this->assertSame(10000.0, $this->solde($client));
        $this->assertSame(StatutCommande::Annulee, $commande->fresh()->statut);
    }

    public function test_seul_un_administrateur_arbitre(): void
    {
        [$commande, $client, $prestataire] = $this->uneCommandeEnAttente();

        $this->actingAs($client)->post(route('admin.commandes.arbitrer', $commande), ['decision' => 'client'])->assertForbidden();
        $this->actingAs($prestataire)->post(route('admin.commandes.arbitrer', $commande), ['decision' => 'prestataire'])->assertForbidden();
    }

    public function test_l_administrateur_voit_toutes_les_commandes_sans_pouvoir_agir(): void
    {
        [$commande] = $this->uneCommandeEnAttente();
        $admin = User::factory()->create(['est_admin' => true, 'quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($admin)->get(route('admin.commandes'))->assertOk()->assertSee('n° '.$commande->id);
        $this->get(route('admin.commandes.voir', $commande))->assertOk()->assertDontSee('Que faire maintenant');
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.commandes.arbitrer'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.commandes.agir'));
    }
}
