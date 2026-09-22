<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Règle 3 : limitation du débit sur la connexion et sur les endpoints sensibles (en plus du décompte des échecs par compte). */
class LimitesDeRequetesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    public function test_la_connexion_est_plafonnee_par_ip_meme_avec_des_comptes_differents(): void
    {
        config(['koudmain.securite.limites.connexion_par_minute_et_ip' => 5]);

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/connexion', ['email' => "inconnu$i@exemple.ci", 'password' => 'Motdepasse1'])->assertStatus(302);
        }

        $this->post('/connexion', ['email' => 'encore@exemple.ci', 'password' => 'Motdepasse1'])->assertStatus(429);
    }

    public function test_le_plafond_de_connexion_bloque_meme_le_bon_mot_de_passe(): void
    {
        User::factory()->create(['email' => 'aya@exemple.ci']);
        config(['koudmain.securite.limites.connexion_par_minute_et_ip' => 2]);

        $this->post('/connexion', ['email' => 'x1@exemple.ci', 'password' => 'a']);
        $this->post('/connexion', ['email' => 'x2@exemple.ci', 'password' => 'a']);

        $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_le_changement_de_mot_de_passe_est_plafonne(): void
    {
        $client = User::factory()->create();
        $donnees = ['mot_de_passe_actuel' => 'faux', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123'];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($client)->put('/compte/mot-de-passe', $donnees)->assertSessionHasErrors('mot_de_passe_actuel');
        }

        $this->actingAs($client)->put('/compte/mot-de-passe', $donnees)->assertStatus(429);
    }

    public function test_les_actions_d_administration_sont_plafonnees_mais_pas_la_lecture(): void
    {
        config(['koudmain.securite.limites.admin_par_minute' => 3]);
        $admin = User::factory()->admin()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($admin)->get('/admin/utilisateurs')->assertOk(); // lire : jamais limité
        }

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->post('/admin/prestataires/999999/valider')->assertStatus(404);
        }

        $this->actingAs($admin)->post('/admin/prestataires/999999/valider')->assertStatus(429);
    }

    public function test_un_plafond_general_protege_toutes_les_pages(): void
    {
        config(['koudmain.securite.limites.global_visiteur_par_minute' => 4]);

        for ($i = 0; $i < 4; $i++) {
            $this->get('/connexion')->assertOk();
        }

        $this->get('/connexion')->assertStatus(429);
    }

    public function test_un_utilisateur_connecte_a_son_propre_compteur(): void
    {
        config(['koudmain.securite.limites.global_utilisateur_par_minute' => 3, 'koudmain.securite.limites.global_visiteur_par_minute' => 3]);
        $a = User::factory()->create();
        $b = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($a)->get('/compte/profil')->assertOk();
        }

        $this->actingAs($a)->get('/compte/profil')->assertStatus(429);
        $this->actingAs($b)->get('/compte/profil')->assertOk(); // b n'est pas pénalisé par a (même adresse IP)
    }

    public function test_chaque_route_a_son_propre_compteur(): void
    {
        // Sans préfixe, tous les « throttle:N,1 » d'un utilisateur partageaient UN seul compteur : quelques actualisations de la cloche
        // ou de la messagerie faisaient répondre « trop de requêtes » au portefeuille.
        $client = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($client)->get('/client/wallet/export')->assertOk();
        }

        $this->actingAs($client)->get('/client/wallet/export')->assertStatus(429);
        $this->actingAs($client)->getJson('/notifications/recentes')->assertOk();
        $this->actingAs($client)->post('/notifications/tout-lire')->assertRedirect();
    }

    public function test_une_reponse_429_porte_quand_meme_les_en_tetes_de_securite(): void
    {
        config(['koudmain.securite.limites.global_visiteur_par_minute' => 1]);

        $this->get('/connexion');
        $reponse = $this->get('/connexion')->assertStatus(429);

        $reponse->assertHeader('X-Frame-Options', 'DENY');
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_les_compteurs_sont_par_ip_pas_par_en_tete_falsifiable(): void
    {
        config(['koudmain.securite.limites.global_visiteur_par_minute' => 2]);

        // Changer l'en-tête X-Forwarded-For (sans proxy de confiance) ne donne pas un nouveau compteur.
        $this->withHeader('X-Forwarded-For', '1.1.1.1')->get('/connexion')->assertOk();
        $this->withHeader('X-Forwarded-For', '2.2.2.2')->get('/connexion')->assertOk();
        $this->withHeader('X-Forwarded-For', '3.3.3.3')->get('/connexion')->assertStatus(429);
    }
}
