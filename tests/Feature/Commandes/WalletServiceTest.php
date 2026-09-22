<?php

namespace Tests\Feature\Commandes;

use App\Events\RetraitTraite;
use App\Exceptions\OperationRefusee;
use App\Exceptions\SoldeInsuffisant;
use App\Models\Retrait;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
    }

    public function test_le_wallet_est_cree_a_la_volee(): void
    {
        $client = $this->unClient();

        $this->assertSame(0, Wallet::query()->where('user_id', $client->id)->count());
        $this->assertSame(0.0, $this->wallets->solde($client));
        $this->assertNotNull($this->wallets->pour($client)->id);
        $this->assertSame(1, Wallet::query()->where('user_id', $client->id)->count());
    }

    public function test_un_credit_puis_un_debit_laissent_le_registre_a_jour(): void
    {
        $client = $this->unClient();

        $this->wallets->mouvement($client, 'credit', 10000, 'Recharge');
        $debit = $this->wallets->mouvement($client, 'debit', 2500.50, 'Achat');

        $this->assertSame(7499.5, $this->solde($client));
        $this->assertSame('7499.50', $debit->solde_apres);
        $this->assertSame(2, WalletTransaction::query()->count());
    }

    public function test_le_solde_ne_peut_pas_devenir_negatif(): void
    {
        $client = $this->unClient(1000);

        try {
            $this->wallets->mouvement($client, 'debit', 1500, 'Trop cher');
            $this->fail('Une exception SoldeInsuffisant était attendue.');
        } catch (SoldeInsuffisant $e) {
            $this->assertStringContainsString('Solde insuffisant', $e->getMessage());
            $this->assertSame(1500.0, $e->requis);
            $this->assertSame(1000.0, $e->disponible);
        }

        $this->assertSame(1000.0, $this->solde($client));
        $this->assertSame(1, WalletTransaction::query()->count()); // seulement le solde de départ
    }

    public function test_les_centimes_ne_derivent_pas(): void
    {
        $client = $this->unClient();

        foreach (range(1, 10) as $_) {
            $this->wallets->mouvement($client, 'credit', 0.1, 'Dixième');
        }

        $this->assertSame('1.00', Wallet::query()->where('user_id', $client->id)->value('solde'));
    }

    public function test_un_montant_nul_ou_negatif_est_refuse(): void
    {
        $client = $this->unClient(100);

        $this->expectException(InvalidArgumentException::class);
        $this->wallets->mouvement($client, 'credit', -5, 'Triche');
    }

    // ------------------------------------------------------------------ Retraits

    private function prestataireRiche(int $solde = 20000): User
    {
        $prestataire = $this->unPrestataire();
        $this->wallets->mouvement($prestataire, 'credit', $solde, 'Gains');

        return $prestataire;
    }

    public function test_un_retrait_debite_tout_de_suite_et_reste_en_attente(): void
    {
        $prestataire = $this->prestataireRiche();

        $retrait = $this->wallets->demanderRetrait($prestataire, 5000, 'Wave', '07 01 02 03 04');

        $this->assertSame(15000.0, $this->solde($prestataire));
        $this->assertSame(Retrait::EN_ATTENTE, $retrait->statut);
        $this->assertSame('0701020304', $retrait->destination);
        $this->assertSame('retrait', WalletTransaction::query()->latest('id')->first()->type);
    }

    public function test_les_regles_du_retrait(): void
    {
        $prestataire = $this->prestataireRiche();
        $refus = fn (callable $appel) => $this->assertThrows($appel, OperationRefusee::class);

        $refus(fn () => $this->wallets->demanderRetrait($prestataire, 500, 'Wave', '0701020304'));                 // sous le minimum
        $refus(fn () => $this->wallets->demanderRetrait($prestataire, 2000, 'Bitcoin', '0701020304'));             // mode inconnu
        $refus(fn () => $this->wallets->demanderRetrait($prestataire, 2000, 'Wave', '12345'));                     // numéro invalide
        $refus(fn () => $this->wallets->demanderRetrait($prestataire, 2000, 'Virement bancaire', 'abc'));          // RIB invalide
        $refus(fn () => $this->wallets->demanderRetrait($this->unClient(5000), 2000, 'Wave', '0701020304'));        // pas un prestataire
        $this->assertThrows(fn () => $this->wallets->demanderRetrait($prestataire, 50000, 'Wave', '0701020304'), SoldeInsuffisant::class);

        $this->assertSame(20000.0, $this->solde($prestataire));
        $this->assertSame(0, Retrait::query()->count());

        $ok = $this->wallets->demanderRetrait($prestataire, 2000, 'Virement bancaire', 'ci93 0100 1234 5678');
        $this->assertSame('CI93010012345678', $ok->destination); // espaces retirés, majuscules
    }

    public function test_confirmer_un_retrait_ne_rend_rien(): void
    {
        Event::fake([RetraitTraite::class]);
        $prestataire = $this->prestataireRiche();
        $admin = User::factory()->create(['est_admin' => true]);
        $retrait = $this->wallets->demanderRetrait($prestataire, 5000, 'Orange Money', '0701020304');

        $this->wallets->confirmerRetrait($admin, $retrait);

        $this->assertSame(Retrait::EFFECTUE, $retrait->fresh()->statut);
        $this->assertSame(15000.0, $this->solde($prestataire));
        Event::assertDispatched(RetraitTraite::class);
    }

    public function test_refuser_un_retrait_rend_l_argent_une_seule_fois(): void
    {
        $prestataire = $this->prestataireRiche();
        $admin = User::factory()->create(['est_admin' => true]);
        $retrait = $this->wallets->demanderRetrait($prestataire, 5000, 'Orange Money', '0701020304');

        $this->wallets->refuserRetrait($admin, $retrait, 'Numéro incorrect');
        $this->assertSame(20000.0, $this->solde($prestataire));

        $this->assertThrows(fn () => $this->wallets->refuserRetrait($admin, $retrait, 'Encore'), OperationRefusee::class);
        $this->assertThrows(fn () => $this->wallets->confirmerRetrait($admin, $retrait), OperationRefusee::class);
        $this->assertSame(20000.0, $this->solde($prestataire));
        $this->assertSame('Numéro incorrect', $retrait->fresh()->motif_refus);
    }

    public function test_seul_un_administrateur_traite_un_retrait_et_le_refus_exige_un_motif(): void
    {
        $prestataire = $this->prestataireRiche();
        $retrait = $this->wallets->demanderRetrait($prestataire, 5000, 'Wave', '0701020304');
        $admin = User::factory()->create(['est_admin' => true]);

        $this->assertThrows(fn () => $this->wallets->confirmerRetrait($prestataire, $retrait), OperationRefusee::class);
        $this->assertThrows(fn () => $this->wallets->refuserRetrait($admin, $retrait, '  '), OperationRefusee::class);
        $this->assertSame(Retrait::EN_ATTENTE, $retrait->fresh()->statut);
    }
}
