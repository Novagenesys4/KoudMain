<?php

namespace Tests\Feature\Performance;

use App\Models\Categorie;
use App\Models\User;
use App\Services\TempsReel\Diffuseur;
use App\Support\Espace\Compteurs;
use App\Support\Referentiel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesCommandes;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** Les leviers de rapidité : moins d'allers-retours vers la base, sans jamais montrer une donnée périmée. */
class RapiditeTest extends TestCase
{
    use CreeDesCommandes, CreeDesPrestations, RefreshDatabase;

    /** Nombre de requêtes SQL posées pendant l'exécution du callback. */
    private function requetes(callable $action): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $action();

        return $n;
    }

    // ------------------------------------------------------------ Référentiel

    public function test_le_referentiel_n_interroge_la_base_qu_une_fois(): void
    {
        $this->unService();
        Referentiel::oublier();

        $premiere = $this->requetes(fn () => Referentiel::categories());
        $suivantes = $this->requetes(fn () => Referentiel::categories());

        $this->assertGreaterThan(0, $premiere);
        $this->assertSame(0, $suivantes);
    }

    public function test_le_referentiel_se_vide_quand_un_service_change(): void
    {
        $service = $this->unService('Coiffure femme', 'Beauté et Coiffure');
        $this->assertSame(['Coiffure femme'], Referentiel::categories()->first()->services->pluck('nom')->all());

        $service->categorie->services()->create(['nom' => 'Coiffure homme']);

        $this->assertEqualsCanonicalizing(['Coiffure femme', 'Coiffure homme'], Referentiel::categories()->first()->services->pluck('nom')->all());
    }

    public function test_le_referentiel_se_vide_quand_une_categorie_ou_un_quartier_change(): void
    {
        $this->unService();
        $avant = Referentiel::categories()->count();
        Categorie::query()->create(['nom' => 'Zoologie']);
        $this->assertSame($avant + 1, Referentiel::categories()->count());

        $quartier = $this->creerQuartier('Marcory');
        $this->assertTrue(Referentiel::villes()->flatMap->quartiers->contains('id', $quartier->id));

        $quartier->delete();
        $this->assertFalse(Referentiel::villes()->flatMap->quartiers->contains('id', $quartier->id));
    }

    public function test_les_categories_sans_service_ne_sont_pas_proposees_aux_prestataires(): void
    {
        $this->unService('Plomberie générale', 'Plomberie');
        Categorie::query()->create(['nom' => 'Vide']);

        $noms = Referentiel::categoriesAvecServices()->pluck('nom')->all();

        $this->assertContains('Plomberie', $noms);
        $this->assertNotContains('Vide', $noms);
    }

    // -------------------------------------------------------------- Compteurs

    public function test_un_compteur_n_est_calcule_qu_une_fois_par_requete(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        $premiere = $this->requetes(fn () => Compteurs::commandesClient($client));
        $suivantes = $this->requetes(function () use ($client) {
            Compteurs::commandesClient($client);
            Compteurs::commandesClient($client);
        });

        $this->assertSame(1, $premiere);
        $this->assertSame(0, $suivantes);
    }

    public function test_les_compteurs_de_deux_personnes_ne_se_melangent_pas(): void
    {
        $a = $this->unClient(1000);
        $b = $this->unClient(2500);

        $this->assertSame(1000.0, Compteurs::solde($a));
        $this->assertSame(2500.0, Compteurs::solde($b));
    }

    // ------------------------------------------------------- Mode du temps réel

    public function test_le_mode_du_temps_reel_peut_etre_impose(): void
    {
        config(['koudmain.temps_reel.mode' => 'sondage']);
        $this->assertSame('sondage', Diffuseur::mode());

        config(['koudmain.temps_reel.mode' => 'flux']);
        $this->assertSame('flux', Diffuseur::mode());
    }

    public function test_en_mode_auto_un_serveur_normal_garde_le_flux(): void
    {
        config(['koudmain.temps_reel.mode' => 'auto']);

        // Les tests tournent en ligne de commande (pas le serveur intégré de PHP) : le flux reste actif.
        $this->assertSame('flux', Diffuseur::mode());
    }

    // ------------------------------------------------------------ Accueil

    public function test_l_accueil_montre_les_quartiers_et_les_prestataires_reels(): void
    {
        $prestataire = $this->unPrestataire();
        \App\Models\Prestation::factory()->for($prestataire, 'prestataire')->create(['service_id' => $this->unService()->id]);
        Cache::forget('accueil.vivant');

        $reponse = $this->get('/')->assertOk();

        $this->assertSame(1, $reponse->viewData('nbPrestataires'));
        $this->assertContains('Riviera 2', $reponse->viewData('quartiers'));
        $this->assertCount(1, $reponse->viewData('prestations'));
    }

    public function test_l_accueil_garde_ses_donnees_vivantes_en_cache(): void
    {
        $prestataire = $this->unPrestataire();
        \App\Models\Prestation::factory()->for($prestataire, 'prestataire')->create(['service_id' => $this->unService()->id]);
        Cache::forget('accueil.vivant');

        $froide = $this->requetes(fn () => $this->get('/')->assertOk());
        $this->assertTrue(Cache::has('accueil.vivant'));
        $chaude = $this->requetes(fn () => $this->get('/')->assertOk());

        $this->assertLessThan($froide, $chaude);
    }

    public function test_l_accueil_sans_prestataire_n_affiche_pas_de_faux_chiffre(): void
    {
        Cache::forget('accueil.vivant');

        $reponse = $this->get('/')->assertOk();

        $this->assertSame(0, $reponse->viewData('nbPrestataires'));
        $this->assertSame([], $reponse->viewData('quartiers'));
        $this->assertSame([], $reponse->viewData('prestations'));
    }
}
