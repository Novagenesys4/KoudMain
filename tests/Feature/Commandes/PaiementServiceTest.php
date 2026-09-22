<?php

namespace Tests\Feature\Commandes;

use App\Exceptions\OperationRefusee;
use App\Models\Paiement;
use App\Models\WalletTransaction;
use App\Services\PaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class PaiementServiceTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private PaiementService $paiements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paiements = app(PaiementService::class);
    }

    public function test_sans_agregateur_configure_on_ne_fait_pas_semblant_d_encaisser(): void
    {
        config(['koudmain.paiement.driver' => 'aucun']);
        $client = $this->unClient();

        $this->assertFalse($this->paiements->actif());
        $this->assertThrows(fn () => $this->paiements->recharger($client, 5000, 'Wave', '0701020304'), OperationRefusee::class, 'pas encore activée');
        $this->assertSame(0.0, $this->solde($client));
        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_la_simulation_credite_le_wallet(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();

        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '07 01 02 03 04');

        $this->assertSame(Paiement::REUSSI, $paiement->statut);
        $this->assertSame('simulation', $paiement->fournisseur);
        $this->assertSame(5000.0, $this->solde($client));
        $this->assertSame('Recharge via Wave', WalletTransaction::query()->latest('id')->value('libelle'));
        $this->assertNotNull($paiement->transaction_id);
    }

    public function test_les_regles_de_la_recharge(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();
        $refus = fn (callable $appel, string $motif) => $this->assertThrows($appel, OperationRefusee::class, $motif);

        $refus(fn () => $this->paiements->recharger($client, 100, 'Wave', '0701020304'), 'minimum');
        $refus(fn () => $this->paiements->recharger($client, 2_000_000, 'Wave', '0701020304'), 'maximum');
        $refus(fn () => $this->paiements->recharger($client, 1000.5, 'Wave', '0701020304'), 'entier');
        $refus(fn () => $this->paiements->recharger($client, 1000, 'Chèque', '0701020304'), 'moyen de paiement');
        $refus(fn () => $this->paiements->recharger($client, 1000, 'Wave', '123'), 'numéro');

        $this->assertSame(0.0, $this->solde($client));
        $this->assertSame(0, Paiement::query()->count());
    }

    public function test_confirmer_deux_fois_ne_credite_qu_une_fois(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();
        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '0701020304');

        $this->paiements->confirmer($paiement->reference);
        $this->paiements->confirmer($paiement->reference);

        $this->assertSame(5000.0, $this->solde($client));
    }

    // ------------------------------------------------------------------ CinetPay

    private function cinetpay(): void
    {
        config([
            'koudmain.paiement.driver' => 'cinetpay',
            'koudmain.paiement.cinetpay' => ['api_key' => 'cle-test', 'site_id' => '123456', 'url' => 'https://cinetpay.test/v2'],
        ]);
    }

    public function test_cinetpay_ouvre_une_page_de_paiement_sans_crediter(): void
    {
        $this->cinetpay();
        Http::fake(['cinetpay.test/v2/payment' => Http::response(['code' => '201', 'message' => 'CREATED', 'data' => ['payment_token' => 'tok', 'payment_url' => 'https://checkout.cinetpay.test/p/tok']])]);
        $client = $this->unClient();

        $paiement = $this->paiements->recharger($client, 5000, 'Orange Money', '0701020304');

        $this->assertSame(Paiement::EN_ATTENTE, $paiement->statut);
        $this->assertSame('https://checkout.cinetpay.test/p/tok', $paiement->url_paiement);
        $this->assertSame(0.0, $this->solde($client));

        Http::assertSent(fn ($req) => $req['apikey'] === 'cle-test' && $req['site_id'] === '123456' && $req['amount'] === 5000 && $req['currency'] === 'XOF' && $req['transaction_id'] === $paiement->reference);
    }

    public function test_cinetpay_credite_apres_verification_aupres_de_l_agregateur(): void
    {
        $this->cinetpay();
        Http::fake([
            'cinetpay.test/v2/payment/check' => Http::response(['code' => '00', 'data' => ['status' => 'ACCEPTED', 'amount' => '5000', 'operator_id' => 'OP1']]),
            'cinetpay.test/v2/payment' => Http::response(['data' => ['payment_token' => 't', 'payment_url' => 'https://checkout.cinetpay.test/p/t']]),
        ]);
        $client = $this->unClient();
        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '0701020304');

        $this->paiements->confirmer($paiement->reference);
        $this->paiements->confirmer($paiement->reference); // notification en double

        $this->assertSame(5000.0, $this->solde($client));
        $this->assertSame(Paiement::REUSSI, $paiement->fresh()->statut);
    }

    public function test_un_montant_paye_different_n_est_jamais_credite(): void
    {
        $this->cinetpay();
        Http::fake([
            'cinetpay.test/v2/payment/check' => Http::response(['data' => ['status' => 'ACCEPTED', 'amount' => '500']]),
            'cinetpay.test/v2/payment' => Http::response(['data' => ['payment_token' => 't', 'payment_url' => 'https://checkout.cinetpay.test/p/t']]),
        ]);
        $client = $this->unClient();
        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '0701020304');

        $this->paiements->confirmer($paiement->reference);

        $this->assertSame(0.0, $this->solde($client));
        $this->assertSame(Paiement::ECHOUE, $paiement->fresh()->statut);
    }

    public function test_en_attente_ou_refuse_ne_credite_pas(): void
    {
        $this->cinetpay();
        Http::fake([
            'cinetpay.test/v2/payment/check' => Http::sequence()
                ->push(['data' => ['status' => 'WAITING_FOR_CUSTOMER']])
                ->push(['data' => ['status' => 'REFUSED']]),
            'cinetpay.test/v2/payment' => Http::response(['data' => ['payment_token' => 't', 'payment_url' => 'https://checkout.cinetpay.test/p/t']]),
        ]);
        $client = $this->unClient();
        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '0701020304');

        $this->paiements->confirmer($paiement->reference);
        $this->assertSame(Paiement::EN_ATTENTE, $paiement->fresh()->statut);

        $this->paiements->confirmer($paiement->reference);
        $this->assertSame(Paiement::ECHOUE, $paiement->fresh()->statut);
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_cinetpay_hors_ligne_ne_credite_pas(): void
    {
        $this->cinetpay();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $client = $this->unClient();

        $paiement = $this->paiements->recharger($client, 5000, 'Wave', '0701020304');

        $this->assertSame(Paiement::ECHOUE, $paiement->statut);
        $this->assertStringContainsString('ne répond pas', $paiement->motif_echec);
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_cinetpay_exige_un_multiple_de_cinq(): void
    {
        $this->cinetpay();
        Http::fake();

        $paiement = $this->paiements->recharger($this->unClient(), 1003, 'Wave', '0701020304');

        $this->assertSame(Paiement::ECHOUE, $paiement->statut);
        Http::assertNothingSent();
    }
}
