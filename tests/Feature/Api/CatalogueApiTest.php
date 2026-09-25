<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class CatalogueApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        $this->figerLeTemps();
    }

    public function test_liste_filtree_et_paginee(): void
    {
        $offre = $this->uneOffre(prix: 15000);
        $this->unAvis($offre, 5, 'Parfait');
        $this->uneOffre(prix: 3000);

        $this->getJson('/api/v1/prestations?tri=prix_asc&par_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.derniere_page', 2)
            ->assertJsonPath('data.0.prix', 3000)
            ->assertJsonStructure(['data' => [['id', 'slug', 'titre', 'prix', 'note', 'nb_avis', 'photo_url', 'service', 'categorie', 'prestataire' => ['id', 'nom_complet', 'verifie']]]]);

        $this->getJson('/api/v1/prestations?tri=note')->assertJsonPath('data.0.id', $offre->id)->assertJsonPath('data.0.note', 5);
    }

    public function test_un_filtre_invalide_est_ignore(): void
    {
        $this->uneOffre();

        $this->getJson('/api/v1/prestations?categorie=abc&zone=x&note_min=9&tri=nimporte&par_page=5000')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_fiche_creneaux_et_prestataire(): void
    {
        $offre = $this->uneOffre(prix: 8000, duree: 60);

        $this->getJson('/api/v1/prestations/'.$offre->slug)
            ->assertOk()->assertJsonPath('data.id', $offre->id)->assertJsonStructure(['data' => ['description', 'photos', 'avis', 'prochain_creneau', 'horaires']]);

        $jours = $this->getJson('/api/v1/prestations/'.$offre->slug.'/creneaux')->assertOk()->json('data.jours');
        $this->assertNotEmpty($jours);
        $this->assertSame('2026-09-21', $jours[0]['date']);
        $this->assertSame('11:00', $jours[0]['heures'][0]); // délai minimal de 2 h

        $this->getJson('/api/v1/prestataires/'.$offre->prestataire_id)->assertOk()->assertJsonCount(1, 'data.prestations');
    }

    public function test_prestation_masquee_ou_prestataire_non_valide_404(): void
    {
        $masquee = $this->uneOffre();
        $masquee->forceFill(['est_active' => false])->save();
        $this->getJson('/api/v1/prestations/'.$masquee->slug)->assertStatus(404)->assertJsonPath('code', 'introuvable');

        $enAttente = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->getJson('/api/v1/prestataires/'.$enAttente->id)->assertStatus(404);
    }

    public function test_referentiel_et_parametres(): void
    {
        $this->unService();

        $this->getJson('/api/v1/referentiel/categories')->assertOk()->assertJsonStructure(['data' => [['id', 'nom', 'services' => [['id', 'nom']]]]]);
        $this->getJson('/api/v1/referentiel/zones')->assertOk()->assertJsonPath('data.0.quartiers.0.nom', 'Riviera 2');
        $this->getJson('/api/v1/parametres')->assertOk()->assertJsonPath('data.recharge.min', 500)->assertJsonPath('data.devise', 'FCFA');
    }
}
