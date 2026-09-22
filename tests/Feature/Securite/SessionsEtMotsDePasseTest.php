<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Règles 5 (hachage moderne) et 9 (sessions qui expirent et s'invalident). */
class SessionsEtMotsDePasseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    // ------------------------------------------------------------------ Règle 5 : mots de passe

    public function test_les_mots_de_passe_sont_haches_avec_bcrypt_ou_argon(): void
    {
        $hash = Hash::make('Motdepasse1');

        $this->assertContains(Hash::info($hash)['algoName'], ['bcrypt', 'argon2i', 'argon2id']);
        $this->assertNotSame('Motdepasse1', $hash);
        $this->assertTrue(Hash::check('Motdepasse1', $hash));
        $this->assertFalse(Hash::check('motdepasse1', $hash));
    }

    public function test_la_configuration_par_defaut_est_bcrypt_avec_un_cout_d_au_moins_12(): void
    {
        // Les tests règlent BCRYPT_ROUNDS=4 pour aller vite ; on lit ici la configuration de production du framework.
        $source = file_get_contents(base_path('vendor/laravel/framework/config/hashing.php'));

        $this->assertStringContainsString("'driver' => env('HASH_DRIVER', 'bcrypt')", $source);
        $this->assertStringContainsString("'rounds' => env('BCRYPT_ROUNDS', 12)", $source);
        $this->assertStringContainsString("'rehash_on_login' => true", $source);
        $this->assertStringContainsString("'verify' => env('HASH_VERIFY', true)", $source); // refuse un hash d'un autre algorithme que celui configuré
        $this->assertFileDoesNotExist(config_path('hashing.php'), 'Un hashing.php local pourrait affaiblir ces valeurs : le contrôle de production (BCRYPT_ROUNDS >= 12) doit rester la référence.');
    }

    public function test_un_mot_de_passe_n_apparait_jamais_en_clair_en_base(): void
    {
        $this->post('/inscription', [
            'role' => 'client', 'prenom' => 'Aya', 'nom' => 'Koné', 'email' => 'aya@exemple.ci', 'telephone' => '0712345678',
            'quartier_id' => \App\Models\Quartier::query()->value('id'), 'password' => 'Motdepasse1', 'password_confirmation' => 'Motdepasse1',
        ]);

        $enBase = \DB::table('users')->where('email', 'aya@exemple.ci')->value('password');

        $this->assertNotNull($enBase);
        $this->assertStringStartsWith('$2y$', $enBase);
        $this->assertStringNotContainsString('Motdepasse1', $enBase);
    }

    public function test_le_journal_ne_contient_jamais_de_mot_de_passe(): void
    {
        $lignes = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$lignes) {
            $lignes[] = $e->message.' '.json_encode($e->context);
        });

        User::factory()->create(['email' => 'aya@exemple.ci']);
        $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'MOT-DE-PASSE-DE-TEST-123']); // échec
        $this->post('/inscription', ['role' => 'client', 'prenom' => 'A', 'nom' => 'B', 'email' => 'x', 'password' => 'MOT-DE-PASSE-DE-TEST-123', 'password_confirmation' => 'MOT-DE-PASSE-DE-TEST-123']);

        $this->assertNotEmpty($lignes, 'Aucune ligne de journal : le test ne prouve rien.');
        $this->assertStringNotContainsString('MOT-DE-PASSE-DE-TEST-123', implode("\n", $lignes));
    }

    // ------------------------------------------------------------------ Règle 9 : sessions

    public function test_une_session_inactive_expire_au_bout_de_2_heures_au_plus(): void
    {
        $this->assertLessThanOrEqual(120, (int) config('session.lifetime'));
        $this->assertGreaterThan(0, (int) config('session.lifetime'));
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_le_middleware_qui_invalide_les_sessions_apres_un_changement_de_mot_de_passe_est_actif(): void
    {
        $groupe = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(\Illuminate\Session\Middleware\AuthenticateSession::class, $groupe);
    }

    public function test_l_identifiant_de_session_change_a_la_connexion(): void
    {
        User::factory()->create(['email' => 'aya@exemple.ci']);

        $this->get('/connexion');
        $avant = session()->getId();

        $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'password'])->assertRedirect();

        $this->assertNotSame($avant, session()->getId(), 'Sans nouvel identifiant, une session volée avant la connexion resterait valable (fixation de session).');
    }

    public function test_la_deconnexion_detruit_la_session(): void
    {
        User::factory()->create(['email' => 'aya@exemple.ci']);
        $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'password']);
        $avant = session()->getId();

        $this->post('/deconnexion')->assertRedirect();

        $this->assertGuest();
        $this->assertNotSame($avant, session()->getId());
    }

    public function test_changer_de_mot_de_passe_deconnecte_les_autres_appareils(): void
    {
        $user = User::factory()->create(['email' => 'aya@exemple.ci']);
        $empreinteAncienne = Auth::guard('web')->hashPasswordForCookie($user->password);

        // Appareil B : une session ouverte AVANT le changement, avec l'empreinte de l'ancien mot de passe.
        $this->actingAs($user)->withSession(['password_hash_web' => $empreinteAncienne])->get('/compte/profil')->assertOk();

        // Appareil A change le mot de passe.
        $this->actingAs($user)->withSession(['password_hash_web' => $empreinteAncienne])->put('/compte/mot-de-passe', [
            'mot_de_passe_actuel' => 'password', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123',
        ])->assertRedirect(route('compte.mot-de-passe'));

        $user->refresh();
        $this->assertTrue(Hash::check('Nouveau123', $user->password));

        // Appareil B revient avec son ancienne empreinte : refusé, renvoyé vers la connexion.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->withSession(['password_hash_web' => $empreinteAncienne])->get('/compte/profil')->assertRedirect(route('connexion'));

        // Appareil A (nouvelle empreinte) reste connecté.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->withSession(['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->password)])->get('/compte/profil')->assertOk();
    }

    public function test_un_administrateur_n_est_jamais_connecte_en_permanence(): void
    {
        User::factory()->admin()->create(['email' => 'admin@exemple.ci']);

        $reponse = $this->post('/connexion', ['email' => 'admin@exemple.ci', 'password' => 'password', 'remember' => '1']);

        $this->assertNull($this->cookieSouvenir($reponse), 'Un administrateur ne doit pas recevoir de cookie « rester connecté ».');
    }

    public function test_le_cookie_rester_connecte_d_un_client_dure_14_jours_au_plus(): void
    {
        User::factory()->create(['email' => 'aya@exemple.ci']);

        $reponse = $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'password', 'remember' => '1']);
        $cookie = $this->cookieSouvenir($reponse);

        $this->assertNotNull($cookie);
        $this->assertLessThanOrEqual(now()->addDays(14)->addMinutes(2)->timestamp, $cookie->getExpiresTime());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_sans_la_case_rester_connecte_aucun_cookie_permanent(): void
    {
        User::factory()->create(['email' => 'aya@exemple.ci']);

        $reponse = $this->post('/connexion', ['email' => 'aya@exemple.ci', 'password' => 'password']);

        $this->assertNull($this->cookieSouvenir($reponse));
    }

    public function test_un_compte_supprime_perd_sa_session_immediatement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/compte/profil')->assertOk();

        $user->delete();

        $this->app['auth']->forgetGuards();
        $this->withSession(['login_web_'.sha1(\Illuminate\Auth\SessionGuard::class) => $user->id])->get('/compte/profil')->assertRedirect(route('connexion'));
    }

    private function cookieSouvenir($reponse): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($reponse->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                return $cookie;
            }
        }

        return null;
    }
}
