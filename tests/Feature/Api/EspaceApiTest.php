<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** API mobile, Phase 4 : double rôle (« Passer en mode prestataire / client ») et en-tête X-Espace. */
class EspaceApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
    }

    public function test_un_client_ouvre_son_espace_prestataire_puis_doit_fournir_son_kyc(): void
    {
        $client = $this->unClient();
        Sanctum::actingAs($client);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.espaces', ['client'])->assertJsonPath('data.kyc', null);

        $this->postJson('/api/v1/auth/espaces/prestataire')->assertOk()
            ->assertJsonPath('data.espaces', ['client', 'prestataire'])
            ->assertJsonPath('data.role', 'client') // l'espace client reste l'espace par défaut tant que le profil n'est pas validé
            ->assertJsonPath('data.kyc', 'a_fournir')
            ->assertJsonPath('message', 'Espace prestataire ouvert. Dernière étape : vérifiez votre identité pour recevoir des commandes.');

        $client->refresh();
        $this->assertTrue($client->est_client);
        $this->assertTrue($client->est_prestataire);
        $this->assertFalse($client->est_valide);
        $this->assertTrue($client->aLeRole('client'));
        $this->assertFalse($client->aLeRole('prestataire'));

        // Idempotent.
        $this->postJson('/api/v1/auth/espaces/prestataire')->assertOk()->assertJsonPath('message', 'Votre espace prestataire est déjà ouvert.');

        // La vérification d'identité lui est désormais ouverte.
        $this->getJson('/api/v1/prestataire/kyc')->assertStatus(403)->assertJsonPath('code', 'telephone_non_verifie');
        $client->forceFill(['telephone_verifie_at' => now()])->save();
        $this->getJson('/api/v1/prestataire/kyc')->assertOk()->assertJsonPath('data.statut', 'a_fournir');
    }

    public function test_un_prestataire_valide_ouvre_son_espace_client(): void
    {
        $pro = $this->unPrestataire();
        Sanctum::actingAs($pro);

        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.peut_recharger', false);

        $this->postJson('/api/v1/auth/espaces/client')->assertOk()
            ->assertJsonPath('data.espaces', ['client', 'prestataire'])
            ->assertJsonPath('data.role', 'prestataire')
            ->assertJsonPath('data.kyc', 'validee');

        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.peut_recharger', true)->assertJsonPath('data.peut_retirer', true);
        $this->postJson('/api/v1/auth/espaces/client')->assertOk()->assertJsonPath('message', 'Votre espace client est déjà ouvert.');
    }

    public function test_x_espace_choisit_le_point_de_vue_des_commandes_et_du_sequestre(): void
    {
        $pro = $this->unPrestataire();
        $offre = $this->uneOffre($pro, 5000);
        $this->commander($this->unClient(20000), $offre);

        $pro->forceFill(['est_client' => true])->save();
        Sanctum::actingAs($pro);

        // Par défaut : l'espace prestataire (profil validé).
        $this->getJson('/api/v1/commandes')->assertOk()->assertJsonPath('meta.role', 'prestataire')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.en_sequestre', 5000);

        // Espace client ouvert dans l'application : ses propres réservations, son propre séquestre.
        $this->getJson('/api/v1/commandes', ['X-Espace' => 'client'])->assertOk()->assertJsonPath('meta.role', 'client')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/wallet', ['X-Espace' => 'client'])->assertOk()->assertJsonPath('data.en_sequestre', 0);

        // « role » explicite l'emporte sur l'en-tête.
        $this->getJson('/api/v1/commandes?role=prestataire', ['X-Espace' => 'client'])->assertOk()->assertJsonPath('meta.role', 'prestataire');
    }

    public function test_x_espace_ne_donne_aucun_espace_que_le_compte_n_a_pas(): void
    {
        $client = $this->unClient();
        Sanctum::actingAs($client);

        $this->getJson('/api/v1/commandes', ['X-Espace' => 'prestataire'])->assertOk()->assertJsonPath('meta.role', 'client');
        $this->getJson('/api/v1/commandes', ['X-Espace' => 'admin'])->assertOk()->assertJsonPath('meta.role', 'client');
        $this->assertSame('client', $client->espaceDemande('prestataire'));
    }

    public function test_un_administrateur_ne_change_pas_d_espace(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.espaces', []);
        $this->postJson('/api/v1/auth/espaces/prestataire')->assertStatus(403)->assertJsonPath('code', 'role_requis');
        $this->postJson('/api/v1/auth/espaces/client')->assertStatus(403)->assertJsonPath('code', 'role_requis');
        $this->assertFalse($admin->refresh()->est_prestataire);
        $this->assertFalse($admin->est_client);
    }

    public function test_le_client_devenu_prestataire_se_connecte_encore_au_site(): void
    {
        $client = $this->unClient();
        $client->forceFill(['est_prestataire' => true, 'est_valide' => false])->save();

        $this->post('/connexion', ['email' => $client->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($client);
        $this->assertSame('client', $client->espace());

        // Une fois le profil validé, l'espace par défaut devient « prestataire ».
        $client->forceFill(['est_valide' => true])->save();
        $this->assertSame('prestataire', $client->fresh()->espace());
    }

    public function test_prestataire_seul_en_attente_toujours_refuse_sur_le_site(): void
    {
        $pro = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->post('/connexion', ['email' => $pro->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
