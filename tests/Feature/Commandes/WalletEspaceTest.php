<?php

namespace Tests\Feature\Commandes;

use App\Models\Paiement;
use App\Models\Retrait;
use App\Models\User;
use App\Services\DisponibiliteService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les pages de l'argent : wallet du client (recharge), du prestataire (retrait), retraits de l'administrateur, retours de paiement, horaires. */
class WalletEspaceTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['est_admin' => true, 'est_client' => false, 'quartier_id' => $this->creerQuartier()->id]);
    }

    // -------------------------------------------------------------------- Client

    public function test_le_wallet_du_client_affiche_solde_historique_et_recharge(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient(7500);

        $this->actingAs($client)->get(route('client.wallet'))->assertOk()
            ->assertSee("7\u{202F}500")->assertSee('Solde de départ (test)')->assertSee('Mode simulation')->assertSee('id="recharge"', false)
            ->assertDontSee('id="retrait"', false);
    }

    public function test_la_recharge_en_simulation_credite_le_solde(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.recharger'), ['montant' => 10000, 'methode' => 'Wave', 'telephone' => '0701020304'])
            ->assertRedirect(route('client.wallet'))->assertSessionHas('succes');

        $this->assertSame(10000.0, $this->solde($client));
        $this->get(route('client.wallet'))->assertSee('Recharge via Wave');
    }

    public function test_sans_agregateur_la_recharge_est_refusee_honnetement(): void
    {
        config(['koudmain.paiement.driver' => 'aucun']);
        $client = $this->unClient();

        $this->actingAs($client)->get(route('client.wallet'))->assertOk()->assertSee('n\'est pas encore activée', false);
        $this->post(route('client.wallet.recharger'), ['montant' => 10000, 'methode' => 'Wave', 'telephone' => '0701020304'])->assertSessionHas('erreur');
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_une_recharge_invalide_rouvre_la_boite_avec_le_message(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);

        $this->actingAs($this->unClient())->post(route('client.wallet.recharger'), ['montant' => 100, 'methode' => 'Wave', 'telephone' => '0701020304'])
            ->assertRedirect(route('client.wallet'))->assertSessionHas('erreur')->assertSessionHas('ouvrir', 'recharge');
    }

    public function test_avec_cinetpay_le_client_est_envoye_chez_l_agregateur_et_le_retour_credite(): void
    {
        config(['koudmain.paiement.driver' => 'cinetpay', 'koudmain.paiement.cinetpay' => ['api_key' => 'k', 'site_id' => '1', 'url' => 'https://cinetpay.test/v2']]);
        Http::fake([
            'cinetpay.test/v2/payment/check' => Http::response(['data' => ['status' => 'ACCEPTED', 'amount' => '5000']]),
            'cinetpay.test/v2/payment' => Http::response(['data' => ['payment_token' => 't', 'payment_url' => 'https://checkout.cinetpay.test/p/t']]),
        ]);
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.recharger'), ['montant' => 5000, 'methode' => 'Orange Money', 'telephone' => '0701020304']);
        $paiement = Paiement::query()->firstOrFail();

        // Étape intermédiaire sur notre site (la CSP interdit la redirection externe directe après un formulaire)…
        $this->get(route('paiements.continuer', $paiement->reference))->assertOk()->assertSee('https://checkout.cinetpay.test/p/t', false)->assertSee('refresh', false);
        $this->assertSame(0.0, $this->solde($client));

        // … puis le client revient : on redemande l'état RÉEL à l'agrégateur avant de créditer.
        $this->get(route('paiements.retour', ['transaction_id' => $paiement->reference]))->assertRedirect(route('client.wallet'))->assertSessionHas('succes');
        $this->assertSame(5000.0, $this->solde($client));

        // La notification de l'agrégateur (webhook, sans CSRF ni connexion) ne recrédite pas.
        auth()->logout();
        $this->post(route('paiements.notification'), ['cpm_trans_id' => $paiement->reference])->assertOk();
        $this->assertSame(5000.0, $this->solde($client));
    }

    public function test_le_retour_de_paiement_d_un_autre_utilisateur_est_ignore(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $proprietaire = $this->unClient();
        $paiement = app(\App\Services\PaiementService::class)->recharger($proprietaire, 5000, 'Wave', '0701020304');

        $this->actingAs($this->unClient())->get(route('paiements.retour', ['transaction_id' => $paiement->reference]))->assertRedirect(route('client.wallet'))->assertSessionMissing('succes');
        $this->get(route('paiements.continuer', $paiement->reference))->assertNotFound();
        $this->post(route('paiements.notification'), ['cpm_trans_id' => 'INCONNU123456'])->assertOk()->assertSee('OK');
    }

    // --------------------------------------------------------------- Prestataire

    public function test_le_prestataire_demande_un_retrait(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 20000, 'Gains');

        $this->actingAs($prestataire)->get(route('prestataire.wallet'))->assertOk()->assertSee('id="retrait"', false)->assertDontSee('id="recharge"', false);

        $this->post(route('prestataire.wallet.retrait'), ['montant' => 5000, 'methode' => 'Wave', 'destination' => '07 01 02 03 04'])->assertSessionHas('succes');
        $this->assertSame(15000.0, $this->solde($prestataire));
        $this->get(route('prestataire.wallet'))->assertSee('En attente')->assertSee('Retrait vers Wave');

        $this->post(route('prestataire.wallet.retrait'), ['montant' => 500, 'methode' => 'Wave', 'destination' => '0701020304'])->assertSessionHas('erreur')->assertSessionHas('ouvrir', 'retrait');
        $this->assertSame(1, Retrait::query()->count());
    }

    public function test_un_client_ne_retire_pas_et_un_prestataire_ne_recharge_pas(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);

        $this->actingAs($this->unClient(9000))->post(route('prestataire.wallet.retrait'), ['montant' => 2000, 'methode' => 'Wave', 'destination' => '0701020304'])->assertForbidden();
        $this->actingAs($this->unPrestataire())->post(route('client.wallet.recharger'), ['montant' => 2000, 'methode' => 'Wave', 'telephone' => '0701020304'])->assertForbidden();
    }

    public function test_les_horaires_se_gerent_et_se_valident(): void
    {
        $prestataire = $this->unPrestataire();

        $this->actingAs($prestataire)->get(route('prestataire.disponibilites'))->assertOk()->assertSee('Vous n\'avez pas encore indiqué d\'horaires', false);

        $this->put(route('prestataire.disponibilites.enregistrer'), ['jours' => ['1' => [['debut' => '08:00', 'fin' => '12:00'], ['debut' => '14:00', 'fin' => '18:00']], '6' => [['debut' => '09:00', 'fin' => '13:00']]]])
            ->assertRedirect(route('prestataire.disponibilites'))->assertSessionHas('succes');
        $this->assertSame([1 => [['08:00', '12:00'], ['14:00', '18:00']], 6 => [['09:00', '13:00']]], app(DisponibiliteService::class)->horaires($prestataire));
        $this->get(route('prestataire.disponibilites'))->assertSee('value="08:00"', false);

        // Fin avant le début, plage incomplète, chevauchement : rien n'est enregistré.
        foreach ([['debut' => '12:00', 'fin' => '08:00'], ['debut' => '09:00', 'fin' => '']] as $mauvaise) {
            $this->put(route('prestataire.disponibilites.enregistrer'), ['jours' => ['2' => [$mauvaise]]])->assertSessionHasErrors('horaires');
        }
        $this->put(route('prestataire.disponibilites.enregistrer'), ['jours' => ['2' => [['debut' => '08:00', 'fin' => '12:00'], ['debut' => '11:00', 'fin' => '15:00']]]])->assertSessionHasErrors('horaires');
        $this->assertArrayHasKey(1, app(DisponibiliteService::class)->horaires($prestataire), 'Les anciens horaires sont conservés après une erreur.');

        $this->put(route('prestataire.disponibilites.enregistrer'), ['jours' => []])->assertSessionHas('succes');
        $this->assertSame([], app(DisponibiliteService::class)->horaires($prestataire));
    }

    // -------------------------------------------------------------------- Admin

    public function test_l_admin_confirme_ou_refuse_les_retraits(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 30000, 'Gains');
        $wallets = app(WalletService::class);
        $premier = $wallets->demanderRetrait($prestataire, 5000, 'Wave', '0701020304');
        $second = $wallets->demanderRetrait($prestataire, 8000, 'Orange Money', '0505050505');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.retraits'))->assertOk()->assertSee('0701020304')->assertSee('0505050505')->assertSee('Virement effectué');
        $this->get(route('admin.tableau-de-bord'))->assertSee('Traiter les retraits')->assertSee('2 demandes');

        $this->post(route('admin.retraits.confirmer', $premier))->assertSessionHas('succes');
        $this->assertSame(Retrait::EFFECTUE, $premier->fresh()->statut);

        $this->post(route('admin.retraits.refuser', $second), [])->assertSessionHasErrors('motif');
        $this->post(route('admin.retraits.refuser', $second), ['motif' => 'Numéro incorrect'])->assertSessionHas('succes');
        $this->assertSame(Retrait::REFUSE, $second->fresh()->statut);
        $this->assertSame(25000.0, $this->solde($prestataire)); // 30000 - 5000 (effectué), le refus rend 8000

        $this->post(route('admin.retraits.confirmer', $second))->assertSessionHas('erreur');
        $this->actingAs($prestataire)->get(route('prestataire.wallet'))->assertSee('Numéro incorrect');
    }

    public function test_les_retraits_sont_reserves_a_l_admin(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 30000, 'Gains');
        $retrait = app(WalletService::class)->demanderRetrait($prestataire, 5000, 'Wave', '0701020304');

        $this->actingAs($prestataire)->get(route('admin.retraits'))->assertForbidden();
        $this->post(route('admin.retraits.confirmer', $retrait))->assertForbidden();
        $this->actingAs($this->unClient())->post(route('admin.retraits.refuser', $retrait), ['motif' => 'x'])->assertForbidden();
        $this->assertSame(Retrait::EN_ATTENTE, $retrait->fresh()->statut);
    }
}
