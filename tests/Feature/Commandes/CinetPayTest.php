<?php

namespace Tests\Feature\Commandes;

use App\Models\Paiement;
use App\Services\Metriques\Sante;
use App\Services\PaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreeDesCartes;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** CinetPay (API « checkout » v2) : canaux, signature des notifications, rattrapage des recharges restées en attente. */
class CinetPayTest extends TestCase
{
    use CreeDesCartes, CreeDesCommandes, RefreshDatabase;

    private const CLE_SECRETE = 'secret-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        $this->configurer();
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function configurer(array $surcharge = []): void
    {
        config([
            'koudmain.paiement.driver' => 'cinetpay',
            'koudmain.paiement.cinetpay' => $surcharge + ['api_key' => 'cle', 'site_id' => '123', 'secret_key' => self::CLE_SECRETE, 'verifier_signature' => true, 'url' => 'https://cinetpay.test/v2'],
        ]);
    }

    private function faux(string $statutVerification = 'ACCEPTED', string $montant = '5000', string $devise = 'XOF'): void
    {
        Http::fake([
            'cinetpay.test/v2/payment/check' => Http::response(['code' => '00', 'message' => 'SUCCES', 'data' => ['status' => $statutVerification, 'amount' => $montant, 'currency' => $devise, 'operator_id' => 'OP9']]),
            'cinetpay.test/v2/payment' => Http::response(['code' => '201', 'message' => 'CREATED', 'data' => ['payment_token' => 'tok', 'payment_url' => 'https://checkout.cinetpay.test/p/tok']]),
        ]);
    }

    /** Une notification telle que CinetPay l'envoie, signée comme dans la documentation (x-token). */
    private function notifier(string $reference, ?string $token = null, bool $signer = true): \Illuminate\Testing\TestResponse
    {
        $champs = [
            'cpm_site_id' => '123', 'cpm_trans_id' => $reference, 'cpm_trans_date' => '2026-09-21 09:05:00', 'cpm_amount' => '5000', 'cpm_currency' => 'XOF',
            'signature' => 'sig', 'payment_method' => 'OM', 'cel_phone_num' => '0701020304', 'cpm_phone_prefixe' => '225', 'cpm_language' => 'fr',
            'cpm_version' => 'V4', 'cpm_payment_config' => 'SINGLE', 'cpm_page_action' => 'PAYMENT', 'cpm_custom' => '', 'cpm_designation' => 'Recharge wallet KoudMain',
            'cpm_error_message' => 'SUCCES',
        ];
        $token ??= $signer ? hash_hmac('sha256', implode('', $champs), self::CLE_SECRETE) : null;

        return $this->post(route('paiements.notification'), $champs, $token === null ? [] : ['x-token' => $token]);
    }

    // ------------------------------------------------------------------ Initialisation

    public function test_un_paiement_mobile_money_demande_le_canal_mobile_money(): void
    {
        $this->faux();
        $paiement = app(PaiementService::class)->recharger($this->unClient(), 5000, 'Orange Money', '0701020304');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment') && $r['channels'] === 'MOBILE_MONEY' && $r['metadata'] === (string) $paiement->id && $r['customer_phone_number'] === '+2250701020304');
    }

    public function test_un_paiement_par_carte_demande_le_canal_carte(): void
    {
        $this->faux();
        $client = $this->unClient();
        $carte = $this->uneCarte($client);

        app(PaiementService::class)->recharger($client, 5000, 'Carte bancaire', null, $carte->id);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment') && $r['channels'] === 'CREDIT_CARD');
    }

    // ------------------------------------------------------------------ Notification signée

    public function test_une_notification_bien_signee_credite_apres_verification(): void
    {
        $this->faux();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Orange Money', '0701020304');

        $this->notifier($paiement->reference)->assertOk();

        $this->assertSame(5000.0, $this->solde($client));
        $this->assertSame(Paiement::REUSSI, $paiement->fresh()->statut);
    }

    public function test_une_notification_mal_signee_est_refusee_et_ne_credite_rien(): void
    {
        $this->faux();
        Log::spy();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Orange Money', '0701020304');

        $this->notifier($paiement->reference, token: 'pas-le-bon-jeton')->assertForbidden();
        $this->notifier($paiement->reference, signer: false)->assertForbidden();   // pas d'en-tête x-token du tout

        $this->assertSame(0.0, $this->solde($client));
        $this->assertSame(Paiement::EN_ATTENTE, $paiement->fresh()->statut);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/payment/check'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'paiement.notification_signature_invalide')->twice();
    }

    /** Règle 17 (fail-closed) : une clé secrète oubliée ne désactive PAS la vérification, elle refuse les notifications. */
    public function test_sans_cle_secrete_la_notification_est_refusee_et_ne_credite_rien(): void
    {
        $this->faux();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Orange Money', '0701020304');

        $this->configurer(['secret_key' => null]);
        $this->notifier($paiement->reference, signer: false)->assertForbidden();
        $this->notifier($paiement->reference)->assertForbidden();
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_une_reference_qui_n_est_pas_un_texte_simple_est_ignoree(): void
    {
        $this->faux();

        $this->post(route('paiements.notification'), ['cpm_trans_id' => ['a', 'b']], ['x-token' => 'x'])->assertForbidden();
        $this->configurer(['verifier_signature' => false]);
        $this->post(route('paiements.notification'), ['cpm_trans_id' => ['a', 'b']])->assertOk();
        $this->post(route('paiements.notification'), ['cpm_trans_id' => str_repeat('A', 5000)])->assertOk();
    }

    public function test_une_notification_sans_signature_passe_si_la_verification_est_coupee(): void
    {
        $this->faux();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        $this->configurer(['verifier_signature' => false]);
        $this->notifier($paiement->reference, signer: false)->assertOk();
        $this->assertSame(5000.0, $this->solde($client));
    }

    public function test_une_devise_inattendue_n_est_pas_creditee(): void
    {
        $this->faux(devise: 'USD');
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        app(PaiementService::class)->confirmer($paiement->reference);

        $this->assertSame(0.0, $this->solde($client));
        $this->assertSame(Paiement::ECHOUE, $paiement->fresh()->statut);
    }

    public function test_un_paiement_en_attente_du_client_n_est_pas_marque_echoue(): void
    {
        $this->faux('WAITING_FOR_CUSTOMER');
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        app(PaiementService::class)->confirmer($paiement->reference);

        $this->assertSame(Paiement::EN_ATTENTE, $paiement->fresh()->statut);
    }

    // ------------------------------------------------------------------ Rattrapage

    public function test_le_rattrapage_credite_une_recharge_dont_la_notification_s_est_perdue(): void
    {
        $this->faux();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        // Trop récent : le client est peut-être encore en train de payer.
        $this->assertSame(['verifiees' => 0, 'creditees' => 0, 'abandonnees' => 0], app(PaiementService::class)->rattraper());
        $this->assertSame(0.0, $this->solde($client));

        $this->travel(3)->minutes();
        $this->assertSame(['verifiees' => 1, 'creditees' => 1, 'abandonnees' => 0], app(PaiementService::class)->rattraper());
        $this->assertSame(5000.0, $this->solde($client));

        // Une seconde passe ne recrédite pas.
        $this->assertSame(['verifiees' => 0, 'creditees' => 0, 'abandonnees' => 0], app(PaiementService::class)->rattraper());
        $this->assertSame(5000.0, $this->solde($client));
        $this->assertSame(Paiement::REUSSI, $paiement->fresh()->statut);
    }

    public function test_le_rattrapage_abandonne_une_recharge_jamais_finalisee_apres_48_heures(): void
    {
        $this->faux('WAITING_FOR_CUSTOMER');
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        $this->travel(10)->minutes();
        $this->assertSame(0, app(PaiementService::class)->rattraper()['abandonnees']);
        $this->assertSame(Paiement::EN_ATTENTE, $paiement->fresh()->statut);

        $this->travel(49)->hours();
        $this->assertSame(1, app(PaiementService::class)->rattraper()['abandonnees']);
        $this->assertSame(Paiement::ANNULE, $paiement->fresh()->statut);
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_une_recharge_payee_tardivement_est_creditee_plutot_qu_abandonnee(): void
    {
        $this->faux('ACCEPTED');
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');

        $this->travel(72)->hours();
        $bilan = app(PaiementService::class)->rattraper();

        $this->assertSame(1, $bilan['creditees']);
        $this->assertSame(0, $bilan['abandonnees']);
        $this->assertSame(5000.0, $this->solde($client));
    }

    public function test_le_rattrapage_ne_touche_pas_aux_paiements_d_un_autre_agregateur(): void
    {
        $this->faux();
        $client = $this->unClient();
        $paiement = app(PaiementService::class)->recharger($client, 5000, 'Wave', '0701020304');
        \Illuminate\Support\Facades\DB::table('paiements')->where('id', $paiement->id)->update(['fournisseur' => 'paydunya']);

        $this->travel(5)->minutes();

        $this->assertSame(0, app(PaiementService::class)->rattraper()['verifiees']);
    }

    public function test_le_rattrapage_est_planifie_toutes_les_cinq_minutes(): void
    {
        $evenement = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'paiements-rattrapage');

        $this->assertNotNull($evenement);
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }

    // ------------------------------------------------------------------ Santé

    public function test_la_page_de_sante_signale_une_adresse_locale_ou_une_cle_secrete_manquante(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->assertSame('attention', collect(app(Sante::class)->controles())->firstWhere('nom', 'Paiement Mobile Money')['niveau']);

        config(['app.url' => 'https://koudmain.onrender.com']);
        $this->configurer(['secret_key' => null]);
        $c = collect(app(Sante::class)->controles())->firstWhere('nom', 'Paiement Mobile Money');
        $this->assertSame('attention', $c['niveau']);
        $this->assertStringContainsString('CINETPAY_SECRET_KEY', $c['detail']);

        $this->configurer();
        $this->assertSame('ok', collect(app(Sante::class)->controles())->firstWhere('nom', 'Paiement Mobile Money')['niveau']);
    }
}
