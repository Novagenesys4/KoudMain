<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** API mobile, Phase 10 : PUT /auth/me (« Informations personnelles » du Profil). */
class ProfilApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    public function test_un_client_modifie_ses_informations(): void
    {
        $client = $this->unClient();
        $angre = $this->creerQuartier('Angré');
        Sanctum::actingAs($client);

        $this->putJson('/api/v1/auth/me', [
            'prenom' => '  Aïcha ', 'nom' => 'Koné', 'quartier_id' => $angre->id, 'notifications_email' => false,
        ])->assertOk()
            ->assertJsonPath('message', 'Vos informations sont enregistrées.')
            ->assertJsonPath('data.prenom', 'Aïcha')
            ->assertJsonPath('data.nom', 'Koné')
            ->assertJsonPath('data.initiales', 'AK')
            ->assertJsonPath('data.quartier.nom', 'Angré')
            ->assertJsonPath('data.notifications_email', false);

        $this->assertSame($angre->id, $client->refresh()->quartier_id);
    }

    public function test_les_regles_et_messages_du_site_s_appliquent(): void
    {
        Sanctum::actingAs($this->unClient());

        $this->putJson('/api/v1/auth/me', ['prenom' => 'A', 'nom' => '4x', 'quartier_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('errors.prenom.0', 'Indiquez votre prénom (2 à 100 lettres).')
            ->assertJsonPath('errors.nom.0', 'Indiquez votre nom (2 à 50 lettres).')
            ->assertJsonPath('errors.quartier_id.0', 'Choisissez votre quartier dans la liste.');
    }

    public function test_le_numero_et_l_email_ne_se_modifient_pas_ici(): void
    {
        $client = $this->unClient();
        Sanctum::actingAs($client);
        $base = ['prenom' => 'Aya', 'nom' => 'Kouassi', 'quartier_id' => $client->quartier_id];

        $this->putJson('/api/v1/auth/me', $base + ['telephone' => '0707070707', 'email' => 'x@exemple.ci'])
            ->assertStatus(422)
            ->assertJsonPath('errors.telephone.0', 'Le numéro ne se modifie pas depuis cet écran.')
            ->assertJsonPath('errors.email.0', 'L\'adresse e-mail ne se modifie pas depuis cet écran.');
    }

    public function test_la_presentation_est_reservee_aux_prestataires(): void
    {
        $client = $this->unClient();
        Sanctum::actingAs($client);
        $this->putJson('/api/v1/auth/me', ['prenom' => 'Aya', 'nom' => 'Kouassi', 'quartier_id' => $client->quartier_id, 'bio' => 'Bonjour'])
            ->assertStatus(422)->assertJsonPath('errors.bio.0', 'La présentation publique est réservée aux prestataires.');

        $pro = $this->unPrestataire();
        Sanctum::actingAs($pro);
        $this->putJson('/api/v1/auth/me', ['prenom' => 'Aya', 'nom' => 'Kouassi', 'quartier_id' => $pro->quartier_id, 'bio' => '<b>Coiffeuse</b> depuis 8 ans'])
            ->assertOk()->assertJsonPath('data.bio', 'Coiffeuse depuis 8 ans');
    }

    public function test_il_faut_etre_connecte(): void
    {
        $this->putJson('/api/v1/auth/me', [])->assertStatus(401);
    }
}
