<?php

namespace Tests\Feature\Espace;

use App\Enums\ActionCommande;
use App\Models\User;
use App\Services\CommandeService;
use App\Services\Metriques\Enregistreur;
use App\Services\Metriques\Sante;
use App\Services\Metriques\TableauMetriques;
use App\Services\Taches\Suivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** La page admin « Métriques et santé » : vrais chiffres, accès réservé, santé honnête. */
class MetriquesTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_la_page_est_reservee_a_l_administrateur(): void
    {
        $this->get('/admin/metriques')->assertRedirect(route('connexion'));
        $this->actingAs($this->unClient())->get('/admin/metriques')->assertForbidden();
        $this->actingAs($this->unPrestataire())->get('/admin/metriques')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/metriques')->assertOk()->assertSee('Métriques')->assertSee('Santé du système');
    }

    public function test_la_page_s_affiche_a_vide(): void
    {
        $this->actingAs($this->admin())->get('/admin/metriques')->assertOk()
            ->assertSee('Aucune commande')
            ->assertSee('Aucune tâche n\'a encore tourné');
    }

    public function test_les_chiffres_viennent_des_vraies_commandes(): void
    {
        $this->figerLeTemps();
        $client = $this->unClient(50000);
        $prestataire = $this->unPrestataire();
        $offre = $this->uneOffre($prestataire, 5000);
        $service = app(CommandeService::class);

        $terminee = $this->commander($client, $offre);
        $service->agir($terminee, $prestataire, ActionCommande::Accepter);
        $service->agir($terminee->refresh(), $prestataire, ActionCommande::Demarrer);
        $service->agir($terminee->refresh(), $prestataire, ActionCommande::Terminer);
        $service->agir($terminee->refresh(), $client, ActionCommande::ConfirmerReception);
        $this->commander($client, $offre, 2, $this->demain('14:00'));

        $m = app(TableauMetriques::class)->pour(30, true);

        $this->assertSame(2, $m['k']['commandes_periode']);
        $this->assertSame(1, $m['k']['terminees_periode']);
        $this->assertSame(1, $m['k']['commandes_ouvertes']);
        $this->assertEquals(5000, $m['k']['libere_periode']);
        $this->assertEquals(10000, $m['k']['sequestre']);
        $this->assertEquals(['terminee' => 1, 'en_attente' => 1], $m['statuts']);
        $this->assertSame(30, count($m['commandes_par_jour']));
        $this->assertSame(2.0, end($m['commandes_par_jour'])['valeur'], 'les commandes du jour sont sur la dernière barre');
        $this->assertSame(['nom' => $offre->service->categorie->nom, 'commandes' => 2, 'montant' => 15000.0], $m['categories'][0]);
        $this->assertSame($offre->titre, $m['prestations'][0]['titre']);

        $this->actingAs($this->admin())->get('/admin/metriques?jours=30')->assertOk()
            ->assertSee('Commandes passées')
            ->assertSee('Commandes par statut')
            ->assertSee($offre->titre);
    }

    public function test_les_periodes_inconnues_reviennent_a_trente_jours(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/metriques?jours=7')->assertOk()->assertSee('Sur les 7 derniers jours');
        $this->actingAs($admin)->get('/admin/metriques?jours=90')->assertOk()->assertSee('Sur les 90 derniers jours');
        $this->actingAs($admin)->get('/admin/metriques?jours=1000000')->assertOk()->assertSee('Sur les 30 derniers jours');
        $this->actingAs($admin)->get('/admin/metriques?jours=abc')->assertOk()->assertSee('Sur les 30 derniers jours');
    }

    public function test_les_chiffres_sont_mis_en_cache_puis_actualises_a_la_demande(): void
    {
        $this->figerLeTemps();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/metriques')->assertOk();
        $this->commander($this->unClient(10000), $this->uneOffre());

        // Le cache garde l'ancien chiffre...
        $this->assertSame(0, app(TableauMetriques::class)->pour(30)['k']['commandes_periode']);
        // ... jusqu'à ce qu'on demande d'actualiser.
        $this->actingAs($admin)->get('/admin/metriques?actualiser=1')->assertOk();
        $this->assertSame(1, app(TableauMetriques::class)->pour(30)['k']['commandes_periode']);
    }

    public function test_les_connexions_comptees_apparaissent(): void
    {
        $m = app(Enregistreur::class);
        $m->evenement('connexion.succes');
        $m->evenement('connexion.echec');
        $m->evenement('connexion.echec');
        $m->evenement('connexion.bloquee');

        $this->assertSame(
            ['connexion.succes' => 1, 'connexion.echec' => 2, 'connexion.bloquee' => 1],
            app(TableauMetriques::class)->pour(7, true)['connexions'],
        );
    }

    public function test_la_variation_depuis_le_premier_instantane_est_calculee(): void
    {
        DB::table('metriques')->insert(['nom' => 'etat.comptes', 'valeur' => 4, 'created_at' => now()->subDays(10)]);
        User::factory()->count(2)->create(['quartier_id' => $this->creerQuartier()->id]);

        $reponse = $this->actingAs($this->admin())->get('/admin/metriques')->assertOk();

        $reponse->assertSee('depuis le '.now()->subDays(10)->translatedFormat('j M'));
    }

    // ------------------------------------------------------------ Santé

    public function test_la_sante_signale_un_planificateur_arrete(): void
    {
        $controles = collect(app(Sante::class)->controles())->keyBy('nom');

        $this->assertSame('attention', $controles['Planificateur de tâches']['niveau'], 'hors production : attention, pas erreur');

        app(Suivi::class)->battement();
        $controles = collect(app(Sante::class)->controles())->keyBy('nom');
        $this->assertSame('ok', $controles['Planificateur de tâches']['niveau']);
    }

    public function test_la_sante_en_production_est_severe(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => true,
            'koudmain.paiement.driver' => 'simulation',
            'koudmain.media.driver' => 'local',
            'mail.default' => 'log',
        ]);

        $c = collect(app(Sante::class)->controles())->keyBy('nom');

        $this->assertSame('erreur', $c['Environnement']['niveau'], 'APP_DEBUG=true en production');
        $this->assertSame('erreur', $c['Paiement Mobile Money']['niveau'], 'la simulation ne doit pas tourner en production');
        $this->assertSame('erreur', $c['Planificateur de tâches']['niveau']);
        $this->assertSame('attention', $c['Stockage des photos']['niveau']);
        $this->assertSame('attention', $c['E-mails']['niveau']);
        $this->assertSame('attention', $c['Suivi des erreurs (Sentry)']['niveau']);
    }

    public function test_la_sante_en_production_bien_configuree_est_en_ordre(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'koudmain.paiement.driver' => 'cinetpay',
            'koudmain.paiement.cinetpay.api_key' => 'cle',
            'koudmain.paiement.cinetpay.site_id' => '123',
            'koudmain.paiement.cinetpay.secret_key' => 'secret',
            'app.url' => 'https://koudmain.onrender.com',
            'koudmain.media.driver' => 'supabase',
            'koudmain.media.supabase' => ['url' => 'https://x.supabase.co', 'cle_service' => 'secret', 'bucket' => 'medias'],
            'mail.default' => 'smtp',
            'koudmain.sentry.dsn' => 'https://cle@o1.ingest.sentry.io/1',
        ]);
        app(Suivi::class)->battement();
        $this->get('/connexion', ['HTTPS' => 'on']);
        $this->app['request']->server->set('HTTPS', 'on');

        $c = collect(app(Sante::class)->controles())->keyBy('nom');

        $this->assertSame('ok', $c['Paiement Mobile Money']['niveau']);
        $this->assertSame('ok', $c['Stockage des photos']['niveau']);
        $this->assertSame('ok', $c['E-mails']['niveau']);
        $this->assertSame('ok', $c['Suivi des erreurs (Sentry)']['niveau']);
        $this->assertSame('ok', $c['Planificateur de tâches']['niveau']);
        $this->assertSame('ok', $c['Base de données']['niveau']);
    }

    public function test_supabase_sans_cle_est_une_erreur(): void
    {
        config(['koudmain.media.driver' => 'supabase', 'koudmain.media.supabase' => ['url' => '', 'cle_service' => null, 'bucket' => 'medias']]);

        $c = collect(app(Sante::class)->controles())->keyBy('nom');

        $this->assertSame('erreur', $c['Stockage des photos']['niveau']);
    }

    public function test_une_tache_en_echec_rend_la_sante_mauvaise(): void
    {
        app(Suivi::class)->suivre('nettoyage', fn () => throw new \RuntimeException('disque plein'));

        $c = collect(app(Sante::class)->controles())->keyBy('nom');
        $synthese = app(Sante::class)->synthese(array_values($c->all()));

        $this->assertSame('erreur', $c['Dernières exécutions']['niveau']);
        $this->assertSame('erreur', $synthese['niveau']);

        $this->actingAs($this->admin())->get('/admin/metriques')->assertOk()->assertSee('nettoyage')->assertSee('Échec');
    }

    public function test_un_controle_qui_plante_ne_fait_pas_tomber_la_page(): void
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache en panne'));

        $c = collect(app(Sante::class)->controles());

        $this->assertTrue($c->contains(fn ($x) => $x['niveau'] === 'erreur' && str_contains($x['detail'], 'cache en panne')));
        $this->assertGreaterThan(5, $c->count(), 'les autres contrôles ont tout de même été faits');
    }

    public function test_la_page_ne_montre_aucun_secret(): void
    {
        config([
            'koudmain.media.supabase' => ['url' => 'https://x.supabase.co', 'cle_service' => 'SUPER-SECRET-SERVICE', 'bucket' => 'medias'],
            'koudmain.sentry.dsn' => 'https://CLEPUBLIQUE123@o1.ingest.sentry.io/1',
            'koudmain.paiement.cinetpay.api_key' => 'CINETPAY-SECRET',
        ]);

        $this->actingAs($this->admin())->get('/admin/metriques')->assertOk()
            ->assertDontSee('SUPER-SECRET-SERVICE')
            ->assertDontSee('CLEPUBLIQUE123')
            ->assertDontSee('CINETPAY-SECRET')
            ->assertDontSee(config('app.key'), false);
    }

    public function test_le_menu_admin_pointe_vers_la_vraie_page(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee(route('admin.metriques'));
        $this->actingAs($this->admin())->get('/admin/metriques')->assertDontSee('Arrive prochainement');
    }
}
