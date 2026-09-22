<?php

namespace Tests\Feature\Surveillance;

use App\Enums\ActionCommande;
use App\Models\User;
use App\Services\CommandeService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Le journal des actions importantes, l'identifiant de requête et les métriques de connexion. */
class AuditEtContexteTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('connexion|ip|127.0.0.1');
    }

    // ------------------------------------------------------------------ Audit

    public function test_une_commande_passee_est_journalisee_sans_donnee_sensible(): void
    {
        $this->figerLeTemps();
        $client = $this->unClient(10000);
        $offre = $this->uneOffre(prix: 5000);
        Log::spy();

        $commande = $this->commander($client, $offre);

        Log::shouldHaveReceived('log')->with('info', 'commande.passee', Mockery::on(
            fn ($c) => $c['commande'] === $commande->id && $c['client'] === $client->id && $c['montant'] === '5000.00',
        ))->once();
    }

    public function test_le_cycle_d_une_commande_laisse_une_trace_a_chaque_etape(): void
    {
        $this->figerLeTemps();
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 5000));
        $service = app(CommandeService::class);
        Log::spy();

        $service->agir($commande, $prestataire, ActionCommande::Accepter);
        $service->agir($commande->refresh(), $prestataire, ActionCommande::Demarrer);
        $service->agir($commande->refresh(), $prestataire, ActionCommande::Terminer);
        $service->agir($commande->refresh(), $client, ActionCommande::ConfirmerReception);

        Log::shouldHaveReceived('log')->with('info', 'commande.changee', Mockery::on(fn ($c) => $c['evenement'] === 'accepter' && $c['acteur'] === $prestataire->id))->once();
        Log::shouldHaveReceived('log')->with('info', 'commande.changee', Mockery::on(fn ($c) => $c['evenement'] === 'terminer'))->once();
        // Le paiement libéré au prestataire est un événement à part : c'est celui qu'on recherche en cas de doute.
        Log::shouldHaveReceived('log')->with('info', 'commande.confirmer_reception', Mockery::on(fn ($c) => $c['montant'] === '5000.00' && $c['acteur'] === $client->id))->once();
    }

    public function test_un_litige_est_journalise(): void
    {
        $this->figerLeTemps();
        $client = $this->unClient(10000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire, 5000));
        $service = app(CommandeService::class);
        $service->agir($commande, $prestataire, ActionCommande::Accepter);
        $service->agir($commande->refresh(), $prestataire, ActionCommande::Demarrer);
        $service->agir($commande->refresh(), $prestataire, ActionCommande::Terminer);
        Log::spy();

        $service->agir($commande->refresh(), $client, ActionCommande::OuvrirLitige, 'Travail non conforme');

        Log::shouldHaveReceived('log')->with('info', 'commande.ouvrir_litige', Mockery::on(fn ($c) => $c['statut'] === 'litige'))->once();
    }

    public function test_un_retrait_est_journalise_avec_un_numero_masque(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 20000, 'Gains');
        Log::spy();

        $wallet = app(WalletService::class);
        $retrait = $wallet->demanderRetrait($prestataire, 5000, 'Wave', '0712345678');
        $wallet->confirmerRetrait(User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]), $retrait);

        Log::shouldHaveReceived('log')->with('info', 'retrait.demande', Mockery::on(
            fn ($c) => $c['montant'] === '5000.00' && $c['destination'] === '***5678' && ! str_contains(json_encode($c), '0712345678'),
        ))->once();
        Log::shouldHaveReceived('log')->with('info', 'retrait.effectue', Mockery::on(fn ($c) => $c['retrait'] === $retrait->id))->once();
    }

    public function test_un_retrait_refuse_est_journalise(): void
    {
        $prestataire = $this->unPrestataire();
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        app(WalletService::class)->mouvement($prestataire, 'credit', 20000, 'Gains');
        $wallet = app(WalletService::class);
        $retrait = $wallet->demanderRetrait($prestataire, 5000, 'Wave', '0712345678');
        Log::spy();

        $wallet->refuserRetrait($admin, $retrait, 'Numéro invalide');

        Log::shouldHaveReceived('log')->with('info', 'retrait.refuse', Mockery::any())->once();
    }

    // ------------------------------------------------------------------ Requête

    public function test_chaque_page_recoit_un_identifiant_de_requete(): void
    {
        $premier = $this->get('/connexion')->assertOk()->headers->get('X-Request-Id');
        $second = $this->get('/connexion')->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $premier);
        $this->assertNotSame($premier, $second);
    }

    public function test_un_identifiant_fourni_par_le_proxy_est_repris_s_il_est_sur(): void
    {
        $this->withHeaders(['X-Request-Id' => 'render-abc-12345'])->get('/connexion')->assertHeader('X-Request-Id', 'render-abc-12345');

        // Un identifiant piégé (retour à la ligne, guillemets...) est remplacé, jamais recopié dans le journal.
        $reponse = $this->withHeaders(['X-Request-Id' => 'x"}\\ {fausse ligne'])->get('/connexion');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $reponse->headers->get('X-Request-Id'));
    }

    public function test_le_journal_porte_la_requete_et_l_utilisateur(): void
    {
        $utilisateur = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        $reponse = $this->actingAs($utilisateur)->get(route('client.tableau-de-bord'))->assertOk();

        $contexte = Log::sharedContext();
        $this->assertSame($reponse->headers->get('X-Request-Id'), $contexte['requete']);
        $this->assertSame($utilisateur->id, $contexte['utilisateur']);
    }

    // ------------------------------------------------------------------ Connexions

    public function test_les_connexions_alimentent_les_metriques_et_le_journal_masque_l_adresse(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci', 'quartier_id' => $this->creerQuartier()->id]);
        Log::spy();

        $this->from('/connexion')->post('/connexion', ['email' => 'client@exemple.ci', 'password' => 'mauvais']);
        $this->post('/connexion', ['email' => 'client@exemple.ci', 'password' => 'password']);

        $this->assertSame(1, DB::table('metriques')->where('nom', 'connexion.echec')->count());
        $this->assertSame(1, DB::table('metriques')->where('nom', 'connexion.succes')->count());
        Log::shouldHaveReceived('log')->with('info', 'connexion.echec', Mockery::on(fn ($c) => $c['email'] === 'c***@exemple.ci'))->once();
        Log::shouldNotHaveReceived('log', ['info', 'connexion.echec', Mockery::on(fn ($c) => str_contains(json_encode($c), 'client@exemple.ci'))]);
    }

    public function test_les_blocages_anti_force_brute_sont_comptes(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci', 'quartier_id' => $this->creerQuartier()->id]);

        foreach (range(1, 6) as $i) {
            $this->from('/connexion')->post('/connexion', ['email' => 'client@exemple.ci', 'password' => 'mauvais']);
        }

        $this->assertSame(5, DB::table('metriques')->where('nom', 'connexion.echec')->count());
        $this->assertSame(1, DB::table('metriques')->where('nom', 'connexion.bloquee')->count());
    }
}
