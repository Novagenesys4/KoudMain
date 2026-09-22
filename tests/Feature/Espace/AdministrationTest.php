<?php

namespace Tests\Feature\Espace;

use App\Models\Categorie;
use App\Models\Prestation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** Ce que fait l'administrateur : valider, suspendre, supprimer des comptes, gérer le catalogue. */
class AdministrationTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    private function enAttente(string $prenom = 'Yao'): User
    {
        return User::factory()->enAttente()->create(['prenom' => $prenom, 'quartier_id' => $this->creerQuartier()->id]);
    }

    // ------------------------------------------------------------ Prestataires

    public function test_valider_un_prestataire_lui_ouvre_l_acces(): void
    {
        $yao = $this->enAttente();

        $this->actingAs($this->admin)->post(route('admin.prestataires.valider', $yao->id))
            ->assertRedirect()->assertSessionHas('succes');

        $this->assertTrue($yao->fresh()->est_valide);
    }

    public function test_suspendre_un_prestataire_le_retire_du_catalogue(): void
    {
        $presta = $this->unPrestataire();
        $prestation = Prestation::factory()->for($presta, 'prestataire')->create(['service_id' => $this->unService()->id]);
        $this->assertTrue(Prestation::query()->visibles()->whereKey($prestation->id)->exists());

        $this->actingAs($this->admin)->post(route('admin.prestataires.suspendre', $presta->id))->assertSessionHas('succes');

        $this->assertFalse($presta->fresh()->est_valide);
        $this->assertFalse(Prestation::query()->visibles()->whereKey($prestation->id)->exists());
        $this->actingAs($presta->fresh())->get('/prestataire')->assertForbidden();
    }

    public function test_on_ne_valide_pas_un_client_ni_un_admin_et_on_ne_suspend_pas_un_client(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($this->admin)->post(route('admin.prestataires.valider', $client->id))->assertSessionHas('erreur');
        $this->actingAs($this->admin)->post(route('admin.prestataires.suspendre', $client->id))->assertSessionHas('erreur');
        $this->actingAs($this->admin)->post(route('admin.prestataires.suspendre', $this->admin->id))->assertSessionHas('erreur');
        $this->assertTrue($this->admin->fresh()->est_admin);
    }

    public function test_la_page_prestataires_liste_les_deux_groupes(): void
    {
        $this->enAttente('Kouadio');
        $this->unPrestataire(['prenom' => 'Awa']);

        $this->actingAs($this->admin)->get('/admin/prestataires')->assertOk()
            ->assertSee('Profils à valider')->assertSee('Kouadio')
            ->assertSee('Prestataires validés')->assertSee('Awa');
    }

    // ------------------------------------------------------------ Suppression

    public function test_refuser_un_profil_supprime_le_compte(): void
    {
        $yao = $this->enAttente();

        $this->actingAs($this->admin)->delete(route('admin.utilisateurs.supprimer', $yao->id))->assertSessionHas('succes');

        $this->assertModelMissing($yao);
    }

    public function test_on_ne_supprime_ni_un_admin_ni_soi_meme(): void
    {
        $autre = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($this->admin)->delete(route('admin.utilisateurs.supprimer', $autre->id))->assertSessionHas('erreur');
        $this->actingAs($this->admin)->delete(route('admin.utilisateurs.supprimer', $this->admin->id))->assertSessionHas('erreur');

        $this->assertModelExists($autre);
        $this->assertModelExists($this->admin);
    }

    public function test_supprimer_un_prestataire_efface_ses_prestations_et_ses_photos(): void
    {
        $presta = $this->unPrestataire();
        $prestation = Prestation::factory()->for($presta, 'prestataire')->create(['service_id' => $this->unService()->id]);
        $photo = $this->unePhoto($prestation);

        $this->actingAs($this->admin)->delete(route('admin.utilisateurs.supprimer', $presta->id))->assertSessionHas('succes');

        $this->assertModelMissing($presta);
        $this->assertModelMissing($prestation);
        $this->assertModelMissing($photo);
    }

    // ------------------------------------------------------------ Utilisateurs

    public function test_la_recherche_d_utilisateurs_ignore_accents_et_casse_et_filtre_par_role(): void
    {
        User::factory()->create(['prenom' => 'Éloïse', 'nom' => 'Konan', 'quartier_id' => $this->creerQuartier()->id]);
        $this->unPrestataire(['prenom' => 'Moussa', 'nom' => 'Traoré']);

        $this->actingAs($this->admin)->get('/admin/utilisateurs?q=eloise')->assertOk()->assertSee('Éloïse')->assertDontSee('Moussa');
        $this->actingAs($this->admin)->get('/admin/utilisateurs?role=prestataire')->assertOk()->assertSee('Moussa')->assertDontSee('Éloïse');
        // Les jokers SQL saisis sont pris au pied de la lettre.
        $this->actingAs($this->admin)->get('/admin/utilisateurs?q=%25')->assertOk()->assertDontSee('Éloïse')->assertSee('Aucun compte');
    }

    public function test_la_liste_des_utilisateurs_est_paginee(): void
    {
        User::factory()->count(20)->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($this->admin)->get('/admin/utilisateurs')->assertOk()->assertSee('Page 1 sur 2');
        $this->actingAs($this->admin)->get('/admin/utilisateurs?page=2')->assertOk()->assertSee('Page 2 sur 2');
    }

    // --------------------------------------------------------------- Catalogue

    public function test_l_admin_ajoute_une_categorie_et_un_service(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categories.creer'), ['nom' => 'Jardinage'])->assertSessionHas('succes');
        $categorie = Categorie::query()->where('nom', 'Jardinage')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.services.creer'), ['categorie_id' => $categorie->id, 'nom' => 'Taille de haies'])->assertSessionHas('succes');

        $this->assertTrue($categorie->services()->where('nom', 'Taille de haies')->exists());
        $this->actingAs($this->admin)->get('/admin/catalogue')->assertOk()->assertSee('Jardinage')->assertSee('Taille de haies');
    }

    public function test_les_doublons_et_les_valeurs_vides_sont_refuses(): void
    {
        $service = $this->unService('Tresses', 'Beauté');

        $this->actingAs($this->admin)->post(route('admin.categories.creer'), ['nom' => 'Beauté'])->assertSessionHasErrors('nom', errorBag: 'categorie');
        $this->actingAs($this->admin)->post(route('admin.categories.creer'), ['nom' => ''])->assertSessionHasErrors('nom', errorBag: 'categorie');
        $this->actingAs($this->admin)->post(route('admin.services.creer'), ['categorie_id' => $service->categorie_id, 'nom' => 'tresses'])->assertSessionHasErrors('nom', errorBag: 'service');
        $this->actingAs($this->admin)->post(route('admin.services.creer'), ['categorie_id' => 99999, 'nom' => 'X'])->assertSessionHasErrors('categorie_id', errorBag: 'service');
    }

    public function test_un_service_utilise_ou_une_categorie_non_vide_ne_se_supprime_pas(): void
    {
        $service = $this->unService('Plomberie', 'Habitat');
        Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create(['service_id' => $service->id]);

        $this->actingAs($this->admin)->delete(route('admin.services.supprimer', $service->id))->assertSessionHas('erreur');
        $this->actingAs($this->admin)->delete(route('admin.categories.supprimer', $service->categorie_id))->assertSessionHas('erreur');

        $this->assertModelExists($service);
    }

    public function test_un_service_libre_et_une_categorie_vide_se_suppriment(): void
    {
        $service = $this->unService('Massage', 'Bien-être');

        $this->actingAs($this->admin)->delete(route('admin.services.supprimer', $service->id))->assertSessionHas('succes');
        $this->assertModelMissing($service);

        $this->actingAs($this->admin)->delete(route('admin.categories.supprimer', $service->categorie_id))->assertSessionHas('succes');
        $this->assertDatabaseMissing('categories', ['id' => $service->categorie_id]);
    }

    // --------------------------------------------------------------- Commandes

    public function test_la_liste_des_commandes_s_affiche_avec_son_filtre_de_statut(): void
    {
        $presta = $this->unPrestataire(['prenom' => 'Awa']);
        $prestation = Prestation::factory()->for($presta, 'prestataire')->create(['service_id' => $this->unService()->id, 'titre' => 'Coupe homme']);
        $this->uneCommande(User::factory()->create(['prenom' => 'Ibrahim', 'quartier_id' => $this->creerQuartier()->id]), $presta, $prestation);

        $this->actingAs($this->admin)->get('/admin/commandes')->assertOk()->assertSee('Coupe homme')->assertSee('Ibrahim')->assertSee('Terminée');
        $this->actingAs($this->admin)->get('/admin/commandes?statut=litige')->assertOk()->assertDontSee('Coupe homme');
        $this->actingAs($this->admin)->get('/admin/commandes?statut=nimporte')->assertOk()->assertSee('Coupe homme'); // statut inconnu : ignoré
    }

    // ---------------------------------------------------------------- Sécurité

    public function test_aucune_action_d_administration_n_est_possible_sans_etre_admin(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $yao = $this->enAttente();
        $service = $this->unService('Massage', 'Bien-être');

        $this->actingAs($client)->post(route('admin.prestataires.valider', $yao->id))->assertForbidden();
        $this->actingAs($client)->delete(route('admin.utilisateurs.supprimer', $yao->id))->assertForbidden();
        $this->actingAs($client)->post(route('admin.categories.creer'), ['nom' => 'Piratage'])->assertForbidden();
        $this->actingAs($client)->delete(route('admin.services.supprimer', $service->id))->assertForbidden();

        $this->assertFalse($yao->fresh()->est_valide);
        $this->assertModelExists($yao);
        $this->assertModelExists($service);
        $this->assertFalse(Categorie::query()->where('nom', 'Piratage')->exists());
    }
}
