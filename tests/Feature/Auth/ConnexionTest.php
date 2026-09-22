<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ConnexionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        RateLimiter::clear('connexion|ip|127.0.0.1');
    }

    private function seConnecter(string $email, string $motDePasse = 'password')
    {
        return $this->from('/connexion')->post('/connexion', ['email' => $email, 'password' => $motDePasse]);
    }

    public function test_la_page_de_connexion_s_affiche(): void
    {
        $this->get('/connexion')->assertOk()->assertSee('Se connecter');
    }

    public function test_un_client_se_connecte_et_arrive_dans_son_espace(): void
    {
        $user = User::factory()->create(['email' => 'client@exemple.ci']);

        $this->seConnecter('client@exemple.ci')->assertRedirect(route('client.tableau-de-bord'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_chaque_role_arrive_dans_son_propre_espace(): void
    {
        User::factory()->prestataire()->create(['email' => 'presta@exemple.ci']);
        User::factory()->admin()->create(['email' => 'admin@exemple.ci']);

        $this->seConnecter('presta@exemple.ci')->assertRedirect(route('prestataire.tableau-de-bord'));
        $this->post('/deconnexion');

        $this->seConnecter('admin@exemple.ci')->assertRedirect(route('admin.tableau-de-bord'));
    }

    public function test_l_e_mail_est_insensible_a_la_casse(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci']);

        $this->seConnecter('  Client@EXEMPLE.ci ')->assertRedirect(route('client.tableau-de-bord'));
        $this->assertAuthenticated();
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci']);

        $this->seConnecter('client@exemple.ci', 'mauvais')
            ->assertSessionHasErrors(['email' => 'Adresse e-mail ou mot de passe incorrect.']);

        $this->assertGuest();
    }

    public function test_un_compte_inexistant_recoit_le_meme_message_qu_un_mauvais_mot_de_passe(): void
    {
        // On ne révèle pas si une adresse est inscrite.
        $this->seConnecter('personne@exemple.ci')
            ->assertSessionHasErrors(['email' => 'Adresse e-mail ou mot de passe incorrect.']);

        $this->assertGuest();
    }

    public function test_un_prestataire_non_valide_ne_peut_pas_se_connecter(): void
    {
        User::factory()->enAttente()->create(['email' => 'presta@exemple.ci']);

        $this->seConnecter('presta@exemple.ci')
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('en attente de validation', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_les_champs_vides_sont_signales(): void
    {
        $this->from('/connexion')->post('/connexion', [])
            ->assertSessionHasErrors(['email' => 'Veuillez saisir votre adresse e-mail.', 'password' => 'Veuillez saisir votre mot de passe.']);
    }

    // ------------------------------------------------------------ Force brute

    public function test_apres_5_echecs_le_compte_est_bloque_meme_avec_le_bon_mot_de_passe(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci']);

        for ($i = 0; $i < 5; $i++) {
            $this->seConnecter('client@exemple.ci', 'mauvais');
        }

        $this->seConnecter('client@exemple.ci', 'password')
            ->assertSessionHasErrors(['email' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.']);

        $this->assertGuest();
    }

    public function test_4_echecs_ne_bloquent_pas(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci']);

        for ($i = 0; $i < 4; $i++) {
            $this->seConnecter('client@exemple.ci', 'mauvais');
        }

        $this->seConnecter('client@exemple.ci', 'password')->assertRedirect(route('client.tableau-de-bord'));
    }

    public function test_une_connexion_reussie_efface_les_echecs_precedents(): void
    {
        User::factory()->create(['email' => 'client@exemple.ci']);

        for ($i = 0; $i < 4; $i++) {
            $this->seConnecter('client@exemple.ci', 'mauvais');
        }
        $this->seConnecter('client@exemple.ci', 'password')->assertRedirect();
        $this->post('/deconnexion');

        // Le compteur est reparti de zéro : 4 nouveaux échecs ne bloquent pas encore.
        for ($i = 0; $i < 4; $i++) {
            $this->seConnecter('client@exemple.ci', 'mauvais');
        }
        $this->seConnecter('client@exemple.ci', 'password')->assertRedirect(route('client.tableau-de-bord'));
    }

    public function test_une_meme_ip_est_bloquee_apres_trop_d_echecs_sur_des_comptes_differents(): void
    {
        config(['koudmain.auth.max_echecs_par_ip' => 3]);

        foreach (['a', 'b', 'c'] as $lettre) {
            $this->seConnecter("$lettre@exemple.ci", 'mauvais');
        }

        $this->seConnecter('autre@exemple.ci', 'mauvais')
            ->assertSessionHasErrors(['email' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.']);
    }

    public function test_l_ip_est_lue_de_la_derniere_entree_de_x_forwarded_for_derriere_un_proxy(): void
    {
        config(['koudmain.trust_proxy' => true, 'koudmain.auth.max_echecs_par_ip' => 2]);

        // Un visiteur malveillant écrit une fausse IP en tête ; le proxy ajoute la vraie à la fin.
        foreach (['1.1.1.1', '2.2.2.2'] as $fausse) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => "$fausse, 203.0.113.9"])
                ->seConnecter("x$fausse@exemple.ci", 'mauvais');
        }

        // Même IP réelle (203.0.113.9) malgré des fausses IP différentes : bloqué.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '3.3.3.3, 203.0.113.9'])
            ->seConnecter('y@exemple.ci', 'mauvais')
            ->assertSessionHasErrors(['email' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.']);

        RateLimiter::clear('connexion|ip|203.0.113.9');
    }

    // ------------------------------------------------------- Mots de passe

    public function test_un_ancien_hash_est_recalcule_a_la_connexion(): void
    {
        $user = User::factory()->create(['email' => 'client@exemple.ci']);
        // Hash bcrypt de coût 10 alors que l'application (en test) utilise un coût de 4 : il doit être recalculé.
        // On écrit directement en base : le cast « hashed » du modèle refuse un hash dont le coût ne correspond pas
        // à la configuration, alors qu'un ancien hash déjà stocké en base se lit sans problème.
        DB::table('users')->where('id', $user->id)->update([
            'password' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 10]),
        ]);
        $this->assertTrue(Hash::needsRehash($user->fresh()->password));

        $this->seConnecter('client@exemple.ci')->assertRedirect();

        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    // ------------------------------------------------- Accès et déconnexion

    public function test_la_deconnexion_termine_la_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/deconnexion')->assertRedirect(route('accueil'));

        $this->assertGuest();
    }

    public function test_un_visiteur_est_renvoye_vers_la_connexion(): void
    {
        $this->get('/client')->assertRedirect(route('connexion'));
        $this->get('/tableau-de-bord')->assertRedirect(route('connexion'));
    }

    public function test_un_utilisateur_connecte_est_renvoye_de_la_page_de_connexion_vers_son_espace(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/connexion')
            ->assertRedirect(route('admin.tableau-de-bord'));
    }

    public function test_l_espace_admin_est_interdit_aux_non_administrateurs(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->prestataire()->create())->get('/admin')->assertForbidden();
    }

    public function test_l_espace_prestataire_est_interdit_aux_clients_et_aux_prestataires_non_valides(): void
    {
        $this->actingAs(User::factory()->create())->get('/prestataire')->assertForbidden();
        $this->actingAs(User::factory()->enAttente()->create())->get('/prestataire')->assertForbidden();
    }

    public function test_chaque_espace_s_affiche_pour_son_role(): void
    {
        $client = User::factory()->create(['prenom' => 'Aya']);
        $client->wallet()->create();
        $this->actingAs($client)->get('/client')->assertOk()->assertSee('Aya');

        $prestataire = User::factory()->prestataire()->create();
        $prestataire->wallet()->create();
        $this->actingAs($prestataire)->get('/prestataire')->assertOk()->assertSee('Espace prestataire');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')->assertOk()->assertSee('Profils à valider');
    }

    public function test_les_en_tetes_de_securite_sont_envoyes(): void
    {
        $reponse = $this->get('/connexion');

        $reponse->assertHeader('X-Frame-Options', 'DENY');
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $reponse->headers->get('Content-Security-Policy'));
    }
}
