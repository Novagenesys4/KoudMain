<?php

namespace Tests\Feature\Catalogue;

use App\Models\Media;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** L'espace prestataire : créer, modifier, masquer, supprimer une prestation et gérer ses photos. */
class GestionPrestationTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private User $moi;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('medias_local');
        $this->moi = $this->unPrestataire();
    }

    private function donnees(array $surcharge = []): array
    {
        return array_merge(['titre' => 'Tresses africaines', 'service_id' => $this->unService()->id, 'prix' => '15000', 'duree_minutes' => '180', 'description' => 'Faites chez vous.'], $surcharge);
    }

    private function maPrestation(array $attributs = []): Prestation
    {
        return Prestation::factory()->for($this->moi, 'prestataire')->create(array_merge(['service_id' => $this->unService()->id], $attributs));
    }

    // ------------------------------------------------------------------ accès

    public function test_les_pages_de_gestion_sont_reservees_aux_prestataires_valides(): void
    {
        $this->get('/prestataire/prestations')->assertRedirect(route('connexion'));

        $this->actingAs(User::factory()->create(['quartier_id' => $this->creerQuartier()->id]))->get('/prestataire/prestations')->assertForbidden();
        $this->actingAs(User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]))->get('/prestataire/prestations/creer')->assertForbidden();
        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertOk();
    }

    public function test_un_client_ne_peut_pas_creer_de_prestation(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($client)->post('/prestataire/prestations', $this->donnees())->assertForbidden();
        $this->assertSame(0, Prestation::query()->count());
    }

    // ------------------------------------------------------------------ création

    public function test_un_prestataire_cree_une_prestation_avec_des_photos(): void
    {
        $reponse = $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees() + ['photos' => [$this->uneImage('a.jpg'), $this->uneImage('b.png')]]);

        $prestation = Prestation::query()->firstOrFail();
        $reponse->assertRedirect(route('prestataire.prestations.modifier', $prestation));
        $this->assertSame($this->moi->id, $prestation->prestataire_id);
        $this->assertSame('Tresses africaines', $prestation->titre);
        $this->assertSame(180, $prestation->duree_minutes);
        $this->assertTrue($prestation->est_active);
        $this->assertMatchesRegularExpression('/^tresses-africaines-[a-z0-9]{6}$/', $prestation->slug);
        $this->assertSame(2, $prestation->medias()->count());
        foreach ($prestation->medias as $photo) {
            Storage::disk('medias_local')->assertExists($photo->chemin);
        }
    }

    public function test_le_prix_est_lu_meme_avec_des_espaces_et_l_unite(): void
    {
        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees(['prix' => '15 000 FCFA']))->assertSessionHasNoErrors();

        $this->assertSame('15000.00', Prestation::query()->firstOrFail()->prix);
    }

    public function test_le_prestataire_n_est_jamais_lu_dans_le_formulaire(): void
    {
        $autre = $this->unPrestataire();

        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees(['prestataire_id' => $autre->id, 'est_active' => 0, 'slug' => 'pirate']));

        $prestation = Prestation::query()->firstOrFail();
        $this->assertSame($this->moi->id, $prestation->prestataire_id);
        $this->assertNotSame('pirate', $prestation->slug);
    }

    public function test_les_balises_html_de_la_description_sont_retirees(): void
    {
        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees(['description' => "Bonjour <script>alert(1)</script><b>vous</b>\n\n\n\n\nMerci"]));

        $this->assertSame("Bonjour alert(1)vous\n\nMerci", Prestation::query()->firstOrFail()->description);
    }

    public function test_validation_du_formulaire(): void
    {
        $this->actingAs($this->moi)->post('/prestataire/prestations', ['titre' => 'ab', 'service_id' => 99999, 'prix' => '50', 'duree_minutes' => '7'])
            ->assertSessionHasErrors(['titre', 'service_id', 'prix', 'duree_minutes']);

        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees(['prix' => '5000000']))->assertSessionHasErrors('prix');
        $this->assertSame(0, Prestation::query()->count());
    }

    public function test_la_duree_est_facultative(): void
    {
        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees(['duree_minutes' => '']))->assertSessionHasNoErrors();

        $this->assertNull(Prestation::query()->firstOrFail()->duree_minutes);
    }

    public function test_une_photo_invalide_annule_toute_la_creation(): void
    {
        // Trop petite (100 x 80) : acceptée par le formulaire, refusée par le traitement d'image.
        $reponse = $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees() + ['photos' => [$this->uneImage('ok.jpg'), $this->uneImage('petite.jpg', 100, 80)]]);

        $reponse->assertSessionHasErrors('photos');
        $this->assertSame(0, Prestation::query()->count(), 'aucune prestation à moitié créée');
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('medias_local')->allFiles(), 'aucun fichier orphelin');
    }

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $faux = UploadedFile::fake()->createWithContent('virus.jpg', '<?php system($_GET["c"]); ?>');

        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees() + ['photos' => [$faux]])->assertSessionHasErrors('photos');  // refusé par le formulaire ou, à défaut, par le traitement d'image
        $this->assertSame(0, Prestation::query()->count());
    }

    public function test_pas_plus_de_six_photos(): void
    {
        $photos = array_map(fn ($n) => $this->uneImage("p$n.jpg"), range(1, 7));

        $this->actingAs($this->moi)->post('/prestataire/prestations', $this->donnees() + ['photos' => $photos])->assertSessionHasErrors('photos');
        $this->assertSame(0, Prestation::query()->count());
    }

    // ------------------------------------------------------------------ modification

    public function test_le_prestataire_modifie_sa_prestation_sans_changer_son_adresse(): void
    {
        $prestation = $this->maPrestation(['titre' => 'Ancien titre']);
        $slug = $prestation->slug;

        $this->actingAs($this->moi)->put("/prestataire/prestations/{$slug}", $this->donnees(['titre' => 'Nouveau titre', 'prix' => '22000']))->assertRedirect();

        $prestation->refresh();
        $this->assertSame('Nouveau titre', $prestation->titre);
        $this->assertSame('22000.00', $prestation->prix);
        $this->assertSame($slug, $prestation->slug, 'les liens déjà partagés restent valables');
    }

    public function test_la_page_de_modification_s_affiche(): void
    {
        $prestation = $this->maPrestation(['titre' => 'Ma prestation']);
        $this->unePhoto($prestation);

        $this->actingAs($this->moi)->get("/prestataire/prestations/{$prestation->slug}/modifier")->assertOk()->assertSee('Ma prestation')->assertSee('Photos');
    }

    public function test_on_ne_touche_pas_a_la_prestation_d_un_autre(): void
    {
        $autre = $this->unPrestataire();
        $sienne = Prestation::factory()->for($autre, 'prestataire')->create(['titre' => 'Intouchable', 'service_id' => $this->unService()->id]);
        $photo = $this->unePhoto($sienne);
        $this->actingAs($this->moi);

        $this->get("/prestataire/prestations/{$sienne->slug}/modifier")->assertForbidden();
        $this->put("/prestataire/prestations/{$sienne->slug}", $this->donnees(['titre' => 'Piratée']))->assertForbidden();
        $this->patch("/prestataire/prestations/{$sienne->slug}/activation")->assertForbidden();
        $this->delete("/prestataire/prestations/{$sienne->slug}")->assertForbidden();
        $this->post("/prestataire/prestations/{$sienne->slug}/photos", ['photos' => [$this->uneImage()]])->assertForbidden();
        $this->delete("/prestataire/prestations/{$sienne->slug}/photos/{$photo->id}")->assertForbidden();
        $this->patch("/prestataire/prestations/{$sienne->slug}/photos/{$photo->id}/principale")->assertForbidden();

        $sienne->refresh();
        $this->assertSame('Intouchable', $sienne->titre);
        $this->assertTrue($sienne->est_active);
        $this->assertSame(1, $sienne->medias()->count());
    }

    public function test_masquer_puis_publier_de_nouveau(): void
    {
        $prestation = $this->maPrestation();

        $this->actingAs($this->moi)->patch("/prestataire/prestations/{$prestation->slug}/activation")->assertRedirect();
        $this->assertFalse($prestation->fresh()->est_active);

        $this->patch("/prestataire/prestations/{$prestation->slug}/activation");
        $this->assertTrue($prestation->fresh()->est_active);
    }

    // ------------------------------------------------------------------ suppression

    public function test_supprimer_une_prestation_efface_aussi_ses_photos(): void
    {
        $prestation = $this->maPrestation();
        $this->actingAs($this->moi)->put("/prestataire/prestations/{$prestation->slug}", $this->donnees());
        $this->post("/prestataire/prestations/{$prestation->slug}/photos", ['photos' => [$this->uneImage(), $this->uneImage()]])->assertSessionHasNoErrors();
        $chemins = $prestation->medias()->pluck('chemin');
        $this->assertCount(2, $chemins);

        $this->delete("/prestataire/prestations/{$prestation->slug}")->assertRedirect(route('prestataire.prestations.index'));

        $this->assertSame(0, Prestation::query()->count());
        $this->assertSame(0, Media::query()->count());
        foreach ($chemins as $chemin) {
            Storage::disk('medias_local')->assertMissing($chemin);
        }
    }

    public function test_une_prestation_deja_commandee_ne_se_supprime_pas(): void
    {
        $prestation = $this->maPrestation();
        $this->uneCommande(User::factory()->create(['quartier_id' => $this->creerQuartier()->id]), $this->moi, $prestation);

        $this->actingAs($this->moi)->delete("/prestataire/prestations/{$prestation->slug}")->assertSessionHas('erreur');

        $this->assertNotNull($prestation->fresh());
    }

    // ------------------------------------------------------------------ photos

    public function test_ajouter_des_photos_respecte_le_maximum(): void
    {
        $prestation = $this->maPrestation();
        $this->actingAs($this->moi);

        $this->post("/prestataire/prestations/{$prestation->slug}/photos", ['photos' => [$this->uneImage(), $this->uneImage(), $this->uneImage(), $this->uneImage()]])->assertSessionHasNoErrors();
        $this->post("/prestataire/prestations/{$prestation->slug}/photos", ['photos' => [$this->uneImage(), $this->uneImage(), $this->uneImage()]])->assertSessionHasErrors('photos');

        $this->assertSame(4, $prestation->medias()->count(), 'la seconde série (4 + 3 > 6) est refusée en bloc');
    }

    public function test_ajouter_sans_choisir_de_photo_donne_une_erreur(): void
    {
        $prestation = $this->maPrestation();

        $this->actingAs($this->moi)->post("/prestataire/prestations/{$prestation->slug}/photos", [])->assertSessionHasErrors('photos');
    }

    public function test_retirer_une_photo_efface_le_fichier(): void
    {
        $prestation = $this->maPrestation();
        $this->actingAs($this->moi)->post("/prestataire/prestations/{$prestation->slug}/photos", ['photos' => [$this->uneImage(), $this->uneImage()]]);
        $photo = $prestation->medias()->first();

        $this->delete("/prestataire/prestations/{$prestation->slug}/photos/{$photo->id}")->assertRedirect();

        $this->assertSame(1, $prestation->medias()->count());
        Storage::disk('medias_local')->assertMissing($photo->chemin);
    }

    public function test_choisir_la_photo_principale_la_place_en_premier(): void
    {
        $prestation = $this->maPrestation();
        $this->actingAs($this->moi)->post("/prestataire/prestations/{$prestation->slug}/photos", ['photos' => [$this->uneImage(), $this->uneImage(), $this->uneImage()]]);
        $troisieme = $prestation->medias()->get()->last();

        $this->patch("/prestataire/prestations/{$prestation->slug}/photos/{$troisieme->id}/principale")->assertRedirect();

        $this->assertSame($troisieme->id, $prestation->medias()->first()->id);
        $this->assertSame([1, 2, 3], $prestation->medias()->pluck('position')->all());
    }

    public function test_une_photo_d_une_autre_prestation_est_introuvable_meme_via_la_sienne(): void
    {
        $miennes = $this->maPrestation();
        $autre = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create(['service_id' => $this->unService()->id]);
        $photoEtrangere = $this->unePhoto($autre);
        $this->actingAs($this->moi);

        $this->delete("/prestataire/prestations/{$miennes->slug}/photos/{$photoEtrangere->id}")->assertNotFound();
        $this->patch("/prestataire/prestations/{$miennes->slug}/photos/{$photoEtrangere->id}/principale")->assertNotFound();
        $this->assertNotNull($photoEtrangere->fresh());

        // Un identifiant démesuré (au-delà d'un bigint) n'est pas une erreur serveur : la route ne l'accepte pas.
        $this->assertContains($this->delete("/prestataire/prestations/{$miennes->slug}/photos/99999999999999999999")->getStatusCode(), [404, 405]);
    }

    public function test_la_liste_montre_mes_prestations_et_seulement_les_miennes(): void
    {
        $this->maPrestation(['titre' => 'La mienne']);
        Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create(['titre' => 'Celle d\'un autre', 'service_id' => $this->unService()->id]);

        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertOk()->assertSee('La mienne')->assertDontSee('Celle d\'un autre');
    }
}
