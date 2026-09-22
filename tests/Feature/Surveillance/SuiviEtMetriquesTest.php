<?php

namespace Tests\Feature\Surveillance;

use App\Models\User;
use App\Services\Metriques\Enregistreur;
use App\Services\Taches\Suivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Le carnet des tâches planifiées, les métriques et les commandes artisan qui s'en servent. */
class SuiviEtMetriquesTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Suivi

    public function test_une_tache_reussie_est_notee_avec_son_resume(): void
    {
        $resume = app(Suivi::class)->suivre('nettoyage', fn () => '12 lignes supprimées');

        $this->assertSame('12 lignes supprimées', $resume);
        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'nettoyage', 'statut' => 'ok', 'resume' => '12 lignes supprimées', 'executions' => 1, 'echecs' => 0]);
    }

    public function test_les_executions_s_additionnent_sur_une_seule_ligne(): void
    {
        $suivi = app(Suivi::class);

        $suivi->suivre('nettoyage', fn () => 'a');
        $suivi->suivre('nettoyage', fn () => 'b');

        $this->assertSame(1, DB::table('taches_planifiees')->count());
        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'nettoyage', 'executions' => 2, 'resume' => 'b']);
    }

    public function test_une_tache_qui_plante_est_notee_en_erreur_sans_propager(): void
    {
        $suivi = app(Suivi::class);

        $resultat = $suivi->suivre('liberation-escrows', fn () => throw new RuntimeException('base injoignable'));

        $this->assertNull($resultat);
        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'liberation-escrows', 'statut' => 'erreur', 'echecs' => 1]);
        $this->assertStringContainsString('base injoignable', (string) DB::table('taches_planifiees')->value('resume'));

        // Et la suivante fonctionne : le carnet compte l'échec et la reprise.
        $suivi->suivre('liberation-escrows', fn () => 'ok');
        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'liberation-escrows', 'statut' => 'ok', 'executions' => 2, 'echecs' => 1]);
    }

    public function test_le_planificateur_est_vivant_seulement_si_son_battement_est_recent(): void
    {
        $suivi = app(Suivi::class);

        $this->assertFalse($suivi->planificateurVivant());

        $suivi->battement();
        $this->assertTrue($suivi->planificateurVivant());

        Carbon::setTestNow(now()->addSeconds(config('koudmain.taches.battement_max_secondes') + 5));
        $this->assertFalse($suivi->planificateurVivant());
    }

    // ------------------------------------------------------------------ Enregistreur

    public function test_un_evenement_connu_est_enregistre(): void
    {
        app(Enregistreur::class)->evenement('connexion.echec');

        $this->assertDatabaseHas('metriques', ['nom' => 'connexion.echec', 'valeur' => 1]);
    }

    public function test_un_nom_inconnu_ou_un_etat_ne_s_ecrit_pas_comme_evenement(): void
    {
        $m = app(Enregistreur::class);

        $m->evenement('n.importe.quoi');
        $m->evenement('etat.comptes', 99);
        $m->evenement('connexion.succes', NAN);

        $this->assertSame(0, DB::table('metriques')->count());
    }

    public function test_l_instantane_photographie_la_plateforme_une_fois_par_jour(): void
    {
        $this->figerLeTemps();
        $client = $this->unClient(10000);
        $this->commander($client, $this->uneOffre(prix: 5000));

        $m = app(Enregistreur::class);

        $this->assertGreaterThan(0, $m->instantane());
        $this->assertSame(0, $m->instantane(), 'une seule photo par jour');
        $this->assertGreaterThan(0, $m->instantane(true), 'sauf si on la force');

        $valeur = fn (string $nom) => (float) DB::table('metriques')->where('nom', $nom)->latest('id')->value('valeur');
        $this->assertSame(1.0, $valeur('etat.commandes_ouvertes'));
        $this->assertSame(5000.0, $valeur('etat.sequestre_fcfa'));
        $this->assertSame(2.0, $valeur('etat.comptes')); // le client et le prestataire ; jamais l'administrateur
    }

    public function test_les_administrateurs_ne_comptent_pas_dans_les_comptes(): void
    {
        User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);

        app(Enregistreur::class)->instantane();

        $this->assertSame(0.0, (float) DB::table('metriques')->where('nom', 'etat.comptes')->value('valeur'));
    }

    // ------------------------------------------------------------------ Commandes artisan

    public function test_la_commande_d_instantane_note_son_passage(): void
    {
        $this->artisan('koudmain:instantane-metriques')->assertSuccessful();
        $this->artisan('koudmain:instantane-metriques')->expectsOutputToContain('déjà pris')->assertSuccessful();

        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'instantane-metriques', 'statut' => 'ok', 'executions' => 2]);
    }

    public function test_le_nettoyage_purge_le_perime_et_garde_le_reste(): void
    {
        $utilisateur = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $notif = fn (string $id, ?string $lue, string $cree) => DB::table('notifications')->insert([
            'id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $utilisateur->id,
            'data' => '{}', 'read_at' => $lue, 'created_at' => $cree, 'updated_at' => $cree,
        ]);
        $notif('11111111-1111-1111-1111-111111111111', now()->subDays(40), now()->subDays(45)); // lue depuis 40 j : purgée
        $notif('22222222-2222-2222-2222-222222222222', null, now()->subDays(10));               // non lue et récente : gardée
        $notif('33333333-3333-3333-3333-333333333333', now()->subDays(2), now()->subDays(3));  // lue récemment : gardée
        $notif('44444444-4444-4444-4444-444444444444', null, now()->subDays(200));              // non lue mais vieille de 200 j : purgée

        DB::table('metriques')->insert([
            ['nom' => 'connexion.echec', 'valeur' => 1, 'created_at' => now()->subDays(200)],
            ['nom' => 'connexion.echec', 'valeur' => 1, 'created_at' => now()->subDays(2)],
            ['nom' => 'etat.comptes', 'valeur' => 5, 'created_at' => now()->subDays(200)], // les états se gardent 2 ans
            ['nom' => 'etat.comptes', 'valeur' => 5, 'created_at' => now()->subDays(800)],
        ]);

        $this->artisan('koudmain:nettoyer')->assertSuccessful();

        $this->assertSame(['22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333'], DB::table('notifications')->orderBy('id')->pluck('id')->all());
        // Restent : l'événement récent, et l'état d'il y a 200 jours (les événements se gardent 180 j, les états 2 ans).
        $this->assertSame([['connexion.echec', 2], ['etat.comptes', 200]], DB::table('metriques')->orderBy('nom')->get()
            ->map(fn ($m) => [$m->nom, (int) round(now()->diffInDays($m->created_at, true))])->all());
        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'nettoyage', 'statut' => 'ok']);
    }

    public function test_le_nettoyage_ne_touche_ni_aux_commandes_ni_aux_messages(): void
    {
        $this->figerLeTemps();
        $this->commander($this->unClient(10000), $this->uneOffre());
        Carbon::setTestNow(now()->addYears(3));

        $this->artisan('koudmain:nettoyer')->assertSuccessful();

        $this->assertSame(1, DB::table('commandes')->count());
        $this->assertSame(1, DB::table('escrows')->count());
    }

    public function test_la_liberation_des_paiements_est_suivie(): void
    {
        $this->artisan('koudmain:liberer-escrows')->assertSuccessful();

        $this->assertDatabaseHas('taches_planifiees', ['nom' => 'liberation-escrows', 'statut' => 'ok']);
    }

    public function test_le_planificateur_connait_toutes_les_taches(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('koudmain:liberer-escrows')
            ->expectsOutputToContain('koudmain:nettoyer')
            ->expectsOutputToContain('koudmain:instantane-metriques')
            ->expectsOutputToContain('battement')
            ->expectsOutputToContain('temps-reel-purge')
            ->assertSuccessful();
    }

    public function test_le_battement_est_ecrit_par_le_planificateur(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertTrue(app(Suivi::class)->planificateurVivant());
    }
}
