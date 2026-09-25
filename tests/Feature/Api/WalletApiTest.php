<?php

namespace Tests\Feature\Api;

use App\Models\Paiement;
use App\Models\Retrait;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    private function client(int $solde = 0): User
    {
        $c = $this->unClient($solde);
        $c->forceFill(['telephone_verifie_at' => now()])->save();

        return $c;
    }

    public function test_solde_et_historique(): void
    {
        Sanctum::actingAs($this->client(12500));

        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.solde', 12500)->assertJsonPath('data.peut_recharger', true)->assertJsonPath('data.peut_retirer', false);
        $this->getJson('/api/v1/wallet/transactions?sens=entree')->assertOk()->assertJsonPath('data.0.montant', 12500)->assertJsonPath('data.0.sens', 'entree');
    }

    public function test_recharge_en_simulation(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->client();
        Sanctum::actingAs($client);

        $this->postJson('/api/v1/wallet/recharges', ['montant' => 10000, 'methode' => 'Orange Money'])
            ->assertCreated()->assertJsonPath('data.statut', 'reussi')->assertJsonPath('meta.solde', 10000);

        $this->postJson('/api/v1/wallet/recharges', ['montant' => 100, 'methode' => 'Orange Money'])->assertStatus(422)->assertJsonPath('code', 'operation_refusee');
        $this->postJson('/api/v1/wallet/recharges', ['montant' => 1000, 'methode' => 'Bitcoin'])->assertStatus(422);
    }

    public function test_recharge_cinetpay_en_attente_puis_confirmee(): void
    {
        config(['koudmain.paiement.driver' => 'cinetpay', 'koudmain.paiement.cinetpay.api_key' => 'k', 'koudmain.paiement.cinetpay.site_id' => 's']);
        Http::fake([
            '*/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/payment/abc', 'payment_token' => 'tok']]),
            '*/payment/check' => Http::sequence()
                ->push(['code' => '662', 'data' => ['status' => 'PENDING']])
                ->push(['code' => '00', 'data' => ['status' => 'ACCEPTED', 'amount' => '5000', 'currency' => 'XOF', 'operator_id' => 'OP1']]),
        ]);

        $client = $this->client();
        Sanctum::actingAs($client);

        $ref = $this->postJson('/api/v1/wallet/recharges', ['montant' => 5000, 'methode' => 'Wave'])
            ->assertCreated()->assertJsonPath('data.statut', 'en_attente')->assertJsonPath('data.url_paiement', 'https://checkout.cinetpay.com/payment/abc')
            ->json('data.reference');

        $this->getJson("/api/v1/wallet/recharges/$ref")->assertOk()->assertJsonPath('data.statut', 'en_attente');
        $this->getJson("/api/v1/wallet/recharges/$ref")->assertOk()->assertJsonPath('data.statut', 'reussi')->assertJsonPath('data.url_paiement', null)->assertJsonPath('meta.solde', 5000);

        // Rejouer la vérification ne crédite pas deux fois.
        $this->getJson("/api/v1/wallet/recharges/$ref")->assertJsonPath('meta.solde', 5000);

        // La recharge d'un autre : 404.
        Sanctum::actingAs($this->client());
        $this->getJson("/api/v1/wallet/recharges/$ref")->assertStatus(404);
    }

    public function test_retrait_prestataire_seulement(): void
    {
        Sanctum::actingAs($this->client(20000));
        $this->postJson('/api/v1/wallet/retraits', ['montant' => 5000, 'methode' => 'Wave', 'destination' => '0701020304'])->assertStatus(403)->assertJsonPath('code', 'role_requis');

        $pro = $this->unPrestataire(['telephone_verifie_at' => now()]);
        app(WalletService::class)->mouvement($pro, 'credit', 30000, 'test');
        Sanctum::actingAs($pro);

        $this->postJson('/api/v1/wallet/retraits', ['montant' => 25000, 'methode' => 'Orange Money', 'destination' => '07 01 02 03 04'])
            ->assertCreated()->assertJsonPath('data.statut', Retrait::EN_ATTENTE)->assertJsonPath('data.destination_masquee', '••••••0304')->assertJsonPath('meta.solde', 5000);
        $this->postJson('/api/v1/wallet/retraits', ['montant' => 25000, 'methode' => 'Orange Money', 'destination' => '0701020304'])
            ->assertStatus(422)->assertJsonPath('code', 'solde_insuffisant');
        $this->getJson('/api/v1/wallet/retraits')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/wallet')->assertJsonPath('data.peut_retirer', true);
    }

    public function test_recharge_desactivee_en_production_par_defaut(): void
    {
        config(['koudmain.paiement.driver' => 'aucun']);
        Sanctum::actingAs($this->client());

        $this->postJson('/api/v1/wallet/recharges', ['montant' => 5000, 'methode' => 'Wave'])->assertStatus(422)->assertJsonPath('code', 'operation_refusee');
        $this->assertSame(0, Paiement::query()->count());
    }
}
