<?php

namespace Tests\Feature\Client;

use App\Models\Prestation;
use App\Models\User;
use App\Services\FavoriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les favoris : le cœur du catalogue, la page « Mes favoris », et ce qui n'a pas le droit d'y entrer. */
class FavorisTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private User $client;

    private Prestation $offre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        $this->client = $this->unClient();
        $this->offre = $this->uneOffre($this->unPrestataire(['est_valide' => true]));
    }

    public function test_le_coeur_ajoute_puis_retire_un_favori(): void
    {
        $this->actingAs($this->client)->postJson(route('client.favoris.basculer', $this->offre))->assertOk()->assertJson(['favori' => true]);
        $this->assertSame(1, DB::table('favoris')->count());

        $this->postJson(route('client.favoris.basculer', $this->offre))->assertOk()->assertJson(['favori' => false]);
        $this->assertSame(0, DB::table('favoris')->count());
    }

    public function test_sans_javascript_le_bouton_revient_sur_la_page(): void
    {
        $this->actingAs($this->client)->from(route('prestations.voir', $this->offre))->post(route('client.favoris.basculer', $this->offre))
            ->assertRedirect(route('prestations.voir', $this->offre))->assertSessionHas('succes', 'Ajouté à vos favoris.');
    }

    public function test_un_favori_ne_s_ajoute_qu_une_fois(): void
    {
        app(FavoriService::class)->basculer($this->client, $this->offre);
        DB::table('favoris')->insertOrIgnore(['user_id' => $this->client->id, 'prestation_id' => $this->offre->id, 'created_at' => now()]);

        $this->assertSame(1, DB::table('favoris')->count());
    }

    public function test_on_ne_met_pas_en_favori_ce_qui_n_est_pas_visible_ou_est_a_soi(): void
    {
        $masquee = $this->uneOffre($this->unPrestataire(['est_valide' => true]));
        $masquee->forceFill(['est_active' => false])->save();
        $nonValide = $this->uneOffre($this->unPrestataire(['est_valide' => false]));

        $this->actingAs($this->client)->postJson(route('client.favoris.basculer', $masquee))->assertNotFound();
        $this->postJson(route('client.favoris.basculer', $nonValide))->assertNotFound();
        $this->assertSame(0, DB::table('favoris')->count());

        $prestataire = $this->offre->prestataire; // un prestataire qui aurait aussi le rôle client ne s'auto-favorise pas
        $prestataire->forceFill(['est_client' => true])->save();
        $this->actingAs($prestataire)->postJson(route('client.favoris.basculer', $this->offre))->assertNotFound();
    }

    public function test_seul_un_client_a_des_favoris(): void
    {
        $this->post(route('client.favoris.basculer', $this->offre))->assertRedirect(route('connexion'));
        $this->actingAs($this->offre->prestataire)->post(route('client.favoris.basculer', $this->offre))->assertForbidden();
    }

    public function test_la_page_liste_les_favoris_visibles_le_plus_recent_d_abord(): void
    {
        $premier = $this->uneOffre($this->unPrestataire(['est_valide' => true]));
        $premier->forceFill(['titre' => 'Tresses africaines'])->save();
        $second = $this->uneOffre($this->unPrestataire(['est_valide' => true]));
        $second->forceFill(['titre' => 'Coupe homme'])->save();
        $service = app(FavoriService::class);

        $service->basculer($this->client, $premier);
        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        $service->basculer($this->client, $second);

        $page = $this->actingAs($this->client)->get(route('client.favoris'))->assertOk();
        $page->assertSeeInOrder(['Coupe homme', 'Tresses africaines']);
        $this->assertSame(2, $service->nombre($this->client));

        // Masquée : elle reste enregistrée mais disparaît de la liste.
        $premier->forceFill(['est_active' => false])->save();
        $this->get(route('client.favoris'))->assertDontSee('Tresses africaines')->assertSee('Coupe homme');
        $this->assertSame(1, $service->nombre($this->client));
    }

    public function test_la_page_vide_invite_a_parcourir_le_catalogue(): void
    {
        $this->actingAs($this->client)->get(route('client.favoris'))->assertOk()->assertSee('Vous n\'avez pas encore de favori');
    }

    public function test_le_catalogue_du_client_marque_ses_favoris_mais_pas_le_catalogue_public(): void
    {
        app(FavoriService::class)->basculer($this->client, $this->offre);

        $this->actingAs($this->client)->get(route('client.catalogue'))->assertOk()->assertSee('favori_url')->assertSee('&quot;favori&quot;:true', false);
        $this->get(route('catalogue'))->assertOk()->assertDontSee('favori_url');
        auth()->logout();
        $this->get(route('catalogue'))->assertOk()->assertDontSee('favori_url');
    }

    public function test_la_page_d_une_prestation_propose_le_bouton_au_client_seulement(): void
    {
        $this->get(route('prestations.voir', $this->offre))->assertOk()->assertDontSee('Ajouter aux favoris');

        $this->actingAs($this->client)->get(route('prestations.voir', $this->offre))->assertOk()->assertSee('Ajouter aux favoris');

        app(FavoriService::class)->basculer($this->client, $this->offre);
        $this->get(route('prestations.voir', $this->offre))->assertSee('Retirer des favoris');
    }

    public function test_supprimer_la_prestation_ou_le_client_emporte_le_favori(): void
    {
        app(FavoriService::class)->basculer($this->client, $this->offre);
        $this->offre->delete();

        $this->assertSame(0, DB::table('favoris')->count());
    }
}
