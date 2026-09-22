<?php

namespace Tests\Feature\Catalogue;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('medias_local');
        $this->creerQuartier();
    }

    private function client(): User
    {
        return User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    public function test_le_profil_demande_une_connexion(): void
    {
        $this->get('/compte/profil')->assertRedirect(route('connexion'));
        $this->post('/compte/avatar', ['avatar' => $this->uneImage()])->assertRedirect(route('connexion'));
    }

    public function test_la_page_s_affiche_pour_un_client_sans_champ_de_presentation(): void
    {
        $this->actingAs($this->client())->get('/compte/profil')->assertOk()->assertSee('Photo de profil')->assertDontSee('Ma présentation');
    }

    public function test_la_page_s_affiche_pour_un_prestataire_avec_le_champ_de_presentation(): void
    {
        $this->actingAs($this->unPrestataire())->get('/compte/profil')->assertOk()->assertSee('Ma présentation');
    }

    public function test_envoyer_puis_remplacer_puis_supprimer_sa_photo(): void
    {
        $user = $this->client();
        $this->actingAs($user);

        $this->post('/compte/avatar', ['avatar' => $this->uneImage()])->assertSessionHasNoErrors();
        $premier = $user->avatar()->firstOrFail();
        Storage::disk('medias_local')->assertExists($premier->chemin);
        $this->assertStringStartsWith("avatars/{$user->id}/", $premier->chemin);

        $this->post('/compte/avatar', ['avatar' => $this->uneImage('autre.png')])->assertSessionHasNoErrors();
        $this->assertSame(1, Media::query()->where('type', Media::TYPE_AVATAR)->count());
        Storage::disk('medias_local')->assertMissing($premier->chemin);

        $this->delete('/compte/avatar')->assertRedirect();
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('medias_local')->allFiles());
    }

    public function test_une_photo_de_profil_invalide_est_refusee(): void
    {
        $this->actingAs($this->client());

        $this->post('/compte/avatar', [])->assertSessionHasErrors('avatar');
        $this->post('/compte/avatar', ['avatar' => $this->uneImage('petite.jpg', 50, 50)])->assertSessionHasErrors('avatar');
        $this->assertSame(0, Media::query()->count());
    }

    public function test_un_prestataire_ecrit_sa_presentation(): void
    {
        $presta = $this->unPrestataire();

        $this->actingAs($presta)->put('/compte/profil', ['bio' => "Bonjour <b>à tous</b>\n\n\n\nMerci"])->assertSessionHasNoErrors();

        $this->assertSame("Bonjour à tous\n\nMerci", $presta->fresh()->bio);
    }

    public function test_la_presentation_est_limitee_a_600_caracteres(): void
    {
        $this->actingAs($this->unPrestataire())->put('/compte/profil', ['bio' => str_repeat('a', 601)])->assertSessionHasErrors('bio');
    }

    public function test_une_presentation_vide_efface_la_precedente(): void
    {
        $presta = $this->unPrestataire(['bio' => 'Ancienne']);

        $this->actingAs($presta)->put('/compte/profil', ['bio' => '  '])->assertSessionHasNoErrors();

        $this->assertNull($presta->fresh()->bio);
    }

    public function test_un_client_ne_peut_pas_ecrire_de_presentation(): void
    {
        $client = $this->client();

        $this->actingAs($client)->put('/compte/profil', ['bio' => 'Je me fais passer pour un pro'])->assertForbidden();
        $this->assertNull($client->fresh()->bio);
    }

    // ---------------------------------------------------------------- Informations personnelles

    public function test_la_page_propose_les_informations_personnelles_a_tous_les_roles(): void
    {
        $autre = $this->creerQuartier('Angré');
        $client = $this->client();

        foreach ([$client, $this->unPrestataire()] as $user) {
            $this->actingAs($user)->get('/compte/profil')->assertOk()
                ->assertSee('Mes informations')
                ->assertSee('name="prenom"', false)
                ->assertSee('name="telephone"', false)
                ->assertSee('value="'.$autre->id.'"', false)
                ->assertSee('value="'.e($user->prenom).'"', false);
        }
    }

    public function test_modifier_ses_informations_personnelles(): void
    {
        $nouveau = $this->creerQuartier('Angré');
        $client = $this->client();
        $ancienEmail = $client->email;

        $this->actingAs($client)->put(route('compte.identite'), [
            'prenom' => '  Awa ',
            'nom' => "N'Guessan-Koné",
            'telephone' => '+225 07 12 34 56 78',
            'quartier_id' => $nouveau->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('succes');

        $client->refresh();
        $this->assertSame('Awa', $client->prenom);
        $this->assertSame("N'Guessan-Koné", $client->nom);
        $this->assertSame('0712345678', $client->telephone);
        $this->assertSame($nouveau->id, $client->quartier_id);
        $this->assertSame($ancienEmail, $client->email);
    }

    public function test_un_prestataire_garde_son_statut_en_modifiant_ses_informations(): void
    {
        $presta = $this->unPrestataire();
        $estValide = $presta->est_valide;

        $this->actingAs($presta)->put(route('compte.identite'), [
            'prenom' => 'Moussa', 'nom' => 'Traoré', 'telephone' => '0102030405', 'quartier_id' => $presta->quartier_id,
            // Champs qu'un utilisateur malveillant pourrait ajouter : ils sont ignorés.
            'est_admin' => 1, 'est_valide' => 1, 'email' => 'pirate@exemple.ci', 'bio' => 'x',
        ])->assertSessionHasNoErrors();

        $presta->refresh();
        $this->assertSame('Moussa', $presta->prenom);
        $this->assertFalse((bool) $presta->est_admin);
        $this->assertSame((bool) $estValide, (bool) $presta->est_valide);
        $this->assertNotSame('pirate@exemple.ci', $presta->email);
        $this->assertNotSame('x', $presta->bio);
    }

    public function test_les_informations_invalides_sont_refusees_avec_les_messages_de_l_inscription(): void
    {
        $client = $this->client();
        $avant = $client->only(['prenom', 'nom', 'telephone', 'quartier_id']);

        $this->actingAs($client)->from('/compte/profil')->put(route('compte.identite'), [
            'prenom' => 'A', 'nom' => '123', 'telephone' => '0812345678', 'quartier_id' => 999999,
        ])->assertRedirect('/compte/profil')->assertSessionHasErrors([
            'prenom' => 'Indiquez votre prénom (2 à 100 lettres).',
            'nom' => 'Indiquez votre nom (2 à 50 lettres).',
            'telephone' => 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.',
            'quartier_id' => 'Choisissez votre quartier dans la liste.',
        ]);

        $this->assertSame($avant, $client->fresh()->only(['prenom', 'nom', 'telephone', 'quartier_id']));
    }

    public function test_les_erreurs_s_affichent_sous_les_champs_et_gardent_la_saisie(): void
    {
        $this->actingAs($this->client())->from('/compte/profil')->put(route('compte.identite'), [
            'prenom' => 'Awa', 'nom' => '', 'telephone' => '0712345678', 'quartier_id' => $this->creerQuartier()->id,
        ]);

        $this->get('/compte/profil')->assertOk()
            ->assertSee('Indiquez votre nom (2 à 50 lettres).')
            ->assertSee('value="Awa"', false);
    }

    public function test_les_informations_demandent_une_connexion(): void
    {
        $this->put(route('compte.identite'), ['prenom' => 'Awa'])->assertRedirect(route('connexion'));
    }
}
