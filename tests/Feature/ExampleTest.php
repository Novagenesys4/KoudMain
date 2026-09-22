<?php

namespace Tests\Feature;

use App\Models\Categorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_d_accueil_s_affiche(): void
    {
        $categorie = Categorie::create(['nom' => 'Beauté et Coiffure']);
        $categorie->services()->create(['nom' => 'Coiffure femme']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Le bon prestataire')
            ->assertSee('Beauté et Coiffure');
    }

    public function test_l_accueil_transmet_les_donnees_reelles_a_l_ile_react(): void
    {
        $categorie = Categorie::create(['nom' => 'Beauté et Coiffure']);
        $categorie->services()->create(['nom' => 'Coiffure femme']);
        $categorie->services()->create(['nom' => 'Coiffure homme']);

        $reponse = $this->get('/')->assertOk()->assertSee('data-island="Landing"', false);

        // L'attribut data-props contient le JSON échappé ; on le relit comme le fait le navigateur.
        preg_match('/data-island="Landing" data-props="([^"]*)"/', $reponse->getContent(), $trouve);
        $props = json_decode(html_entity_decode($trouve[1] ?? '', ENT_QUOTES), true);

        $this->assertSame('Beauté et Coiffure', $props['domaines'][0]['nom']);
        $this->assertSame(2, $props['domaines'][0]['services_count']);
        $this->assertFalse($props['connecte']);

        $libelles = array_column($props['stats'], 'valeur', 'libelle');
        $this->assertSame(1, $libelles['Domaines de services']);
        $this->assertSame(2, $libelles['Types de services']);
    }

    public function test_l_entete_recoit_l_etat_de_connexion(): void
    {
        $this->get('/')->assertOk()->assertSee('data-island="SiteHeader"', false);
    }

    public function test_la_vitrine_des_composants_s_affiche_hors_production(): void
    {
        $this->get('/composants')->assertOk()->assertSee('data-island="ComponentsDemo"', false);
    }

    public function test_une_adresse_inconnue_renvoie_la_page_404_en_francais(): void
    {
        $this->get('/cette-page-n-existe-pas')
            ->assertNotFound()
            ->assertSee('Page introuvable');
    }
}
