<?php

namespace Tests\Feature\Catalogue;

use App\Models\Prestation;
use App\Models\User;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** La page d'une prestation et le profil public d'un prestataire. */
class PagesPubliquesTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private User $prestataire;

    private Prestation $prestation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prestataire = $this->unPrestataire(['prenom' => 'Mariam', 'nom' => 'Traoré', 'email' => 'mariam@secret.ci', 'telephone' => '0708091011', 'bio' => 'Coiffeuse depuis 9 ans.']);
        $this->prestation = Prestation::factory()->for($this->prestataire, 'prestataire')->create([
            'titre' => 'Tresses africaines',
            'description' => "Ligne 1\nLigne 2",
            'prix' => 15000,
            'duree_minutes' => 90,
            'service_id' => $this->unService()->id,
        ]);
    }

    private function url(?Prestation $prestation = null): string
    {
        return '/prestations/'.($prestation ?? $this->prestation)->slug;
    }

    // ------------------------------------------------------------------ page d'une prestation

    public function test_la_page_publique_affiche_les_informations(): void
    {
        $this->get($this->url())->assertOk()
            ->assertSee('Tresses africaines')
            ->assertSee('Ligne 1')
            ->assertSee('Mariam Traoré')
            ->assertSee(Format::montant(15000))
            ->assertSee('1 h 30')
            ->assertSee('Riviera 2')
            ->assertSee('Se connecter pour commander');
    }

    public function test_l_adresse_avec_un_slug_inconnu_donne_404(): void
    {
        $this->get('/prestations/n-existe-pas-abc123')->assertNotFound();
    }

    public function test_ni_l_e_mail_ni_le_telephone_du_prestataire_ne_sont_publics(): void
    {
        foreach ([$this->url(), '/prestataires/'.$this->prestataire->id, '/prestations'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('mariam@secret.ci')->assertDontSee('0708091011');
        }
    }

    public function test_une_prestation_masquee_n_existe_pas_pour_le_public(): void
    {
        $this->prestation->update(['est_active' => false]);

        $this->get($this->url())->assertNotFound();

        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->actingAs($client)->get($this->url())->assertNotFound();
    }

    public function test_le_proprietaire_et_l_admin_voient_une_prestation_masquee_avec_un_bandeau(): void
    {
        $this->prestation->update(['est_active' => false]);

        $this->actingAs($this->prestataire)->get($this->url())->assertOk()->assertSee('masquée')->assertSee('noindex', false)->assertSee('Modifier ma prestation');
        $this->actingAs(User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]))->get($this->url())->assertOk()->assertSee('masquée');
    }

    public function test_la_prestation_d_un_prestataire_non_valide_est_introuvable(): void
    {
        $this->prestataire->forceFill(['est_valide' => false])->save();

        $this->get($this->url())->assertNotFound();
    }

    public function test_le_bouton_depend_du_role_du_visiteur(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($client)->get($this->url())->assertSee('Commander')->assertSee('/client/prestations/', false)->assertDontSee('Se connecter pour commander');
        $this->actingAs($this->prestataire)->get($this->url())->assertSee('Modifier ma prestation');
    }

    public function test_les_avis_sont_affiches_et_la_note_calculee(): void
    {
        $this->unAvis($this->prestation, 5, 'Excellent travail');
        $this->unAvis($this->prestation, 4);

        $reponse = $this->get($this->url())->assertOk()->assertSee('Excellent travail')->assertSee('4,5')->assertSee('2 avis');
        $this->assertStringContainsString('"ratingValue":4.5', $reponse->getContent());
        $reponse->assertDontSee('Nouveau : pas encore d');
    }

    public function test_sans_avis_pas_de_fausse_note(): void
    {
        $reponse = $this->get($this->url())->assertOk()->assertSee('Nouveau : pas encore d');

        $this->assertStringNotContainsString('aggregateRating', $reponse->getContent(), 'Google ne doit pas recevoir une note qui n\'existe pas');
    }

    public function test_le_titre_est_echappe_partout_meme_dans_les_donnees_structurees(): void
    {
        $this->prestation->update(['titre' => 'Coiffure </script><script>alert(1)</script>']);

        $reponse = $this->get($this->url())->assertOk();
        $html = $reponse->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        // Le bloc JSON-LD reste un JSON valide et lisible malgré le titre hostile.
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $bloc);
        $this->assertSame('Coiffure </script><script>alert(1)</script>', json_decode($bloc[1], true)['name']);
    }

    public function test_la_page_a_une_adresse_canonique_et_des_meta_de_partage(): void
    {
        $this->unePhoto($this->prestation);

        $this->get($this->url())->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('prestations.voir', $this->prestation).'"', false)
            ->assertSee('og:image', false)
            ->assertSee('"@type":"Service"', false);
    }

    public function test_les_prestations_du_meme_prestataire_sont_proposees_sans_doublon(): void
    {
        $autre = Prestation::factory()->for($this->prestataire, 'prestataire')->create(['titre' => 'Coiffure de mariage', 'service_id' => $this->prestation->service_id]);

        $reponse = $this->get($this->url())->assertOk();

        $this->assertSame([$autre->id], array_column($reponse->viewData('memePrestataire'), 'id'));
        $this->assertSame([], $reponse->viewData('memeCategorie'), 'déjà montrée au-dessus');
    }

    // ------------------------------------------------------------------ profil public

    public function test_le_profil_d_un_prestataire_valide_s_affiche(): void
    {
        $this->get('/prestataires/'.$this->prestataire->id)->assertOk()
            ->assertSee('Mariam Traoré')
            ->assertSee('Coiffeuse depuis 9 ans.')
            ->assertSee('Tresses africaines')
            ->assertSee('Prestations');
    }

    public function test_le_profil_d_un_client_d_un_prestataire_non_valide_ou_inconnu_donne_404(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $attente = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->get('/prestataires/'.$client->id)->assertNotFound();
        $this->get('/prestataires/'.$attente->id)->assertNotFound();
        $this->get('/prestataires/999999')->assertNotFound();
        $this->get('/prestataires/abc')->assertNotFound();
        $this->get('/prestataires/99999999999999999999')->assertNotFound();
    }

    public function test_le_profil_liste_les_avis_de_toutes_ses_prestations(): void
    {
        $autre = Prestation::factory()->for($this->prestataire, 'prestataire')->create(['titre' => 'Coiffure de mariage', 'service_id' => $this->prestation->service_id]);
        $this->unAvis($this->prestation, 5, 'Très bien');
        $this->unAvis($autre, 3, 'Correct');

        $this->get('/prestataires/'.$this->prestataire->id)->assertOk()->assertSee('Très bien')->assertSee('Correct')->assertSee('4,0')->assertSee('2 avis');
    }

    public function test_le_profil_n_affiche_que_les_prestations_visibles(): void
    {
        Prestation::factory()->for($this->prestataire, 'prestataire')->masquee()->create(['titre' => 'Brouillon caché', 'service_id' => $this->prestation->service_id]);

        $this->get('/prestataires/'.$this->prestataire->id)->assertOk()->assertDontSee('Brouillon caché');
    }
}
