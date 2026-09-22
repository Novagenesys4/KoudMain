<?php

namespace Tests\Feature\Securite;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Règles 8 (HTTPS + HSTS), 13 (CORS) et 14 (erreurs détaillées coupées en production). */
class TransportEtErreursTest extends TestCase
{
    use RefreshDatabase;

    /** Évalue un fichier de configuration comme si l'application tournait avec ces variables d'environnement. @param array<string, string> $variables */
    private function configAvec(string $fichier, array $variables): array
    {
        $ancien = [];

        foreach ($variables as $cle => $valeur) {
            $ancien[$cle] = [$_ENV[$cle] ?? null, $_SERVER[$cle] ?? null];
            $_ENV[$cle] = $_SERVER[$cle] = $valeur;
            putenv("$cle=$valeur");
        }

        try {
            return require config_path($fichier);
        } finally {
            foreach ($ancien as $cle => [$env, $server]) {
                $env === null ? $this->retirer($_ENV, $cle) : $_ENV[$cle] = $env;
                $server === null ? $this->retirer($_SERVER, $cle) : $_SERVER[$cle] = $server;
                $env === null ? putenv($cle) : putenv("$cle=$env");
            }
        }
    }

    private function retirer(array &$tableau, string $cle): void
    {
        unset($tableau[$cle]);
    }

    // ------------------------------------------------------------------ Règle 8 : HTTPS

    public function test_http_est_redirige_vers_https_en_production(): void
    {
        config(['koudmain.securite.forcer_https' => true, 'app.url' => 'https://koudmain.example']);

        $this->get('http://koudmain.example/connexion?a=1')
            ->assertStatus(301)
            ->assertRedirect('https://koudmain.example/connexion?a=1');
    }

    public function test_la_redirection_ne_suit_jamais_l_en_tete_host_falsifie(): void
    {
        config(['koudmain.securite.forcer_https' => true, 'app.url' => 'https://koudmain.example']);

        $this->withHeader('Host', 'pirate.example')->get('http://pirate.example/connexion')
            ->assertStatus(301)
            ->assertRedirect('https://koudmain.example/connexion');
    }

    public function test_le_controle_de_sante_reste_joignable_en_http(): void
    {
        config(['koudmain.securite.forcer_https' => true, 'app.url' => 'https://koudmain.example']);

        $this->get('http://koudmain.example/up')->assertOk();
    }

    public function test_une_requete_https_passe_sans_redirection(): void
    {
        config(['koudmain.securite.forcer_https' => true, 'app.url' => 'https://koudmain.example']);

        $this->get('https://koudmain.example/connexion')->assertOk();
    }

    public function test_hsts_est_envoye_en_production_sur_https_seulement(): void
    {
        $this->app['env'] = 'production';

        $https = $this->get('https://koudmain.example/connexion');
        $https->assertHeader('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=31536000', $https->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('includeSubDomains', $https->headers->get('Strict-Transport-Security'));

        $this->get('http://koudmain.example/connexion')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_est_aussi_sur_les_pages_d_erreur_https(): void
    {
        $this->app['env'] = 'production';

        $this->get('https://koudmain.example/page-qui-n-existe-pas')->assertNotFound()->assertHeader('Strict-Transport-Security');
    }

    public function test_les_cookies_de_session_sont_securises_en_production(): void
    {
        // Variables vides = valeurs par défaut (un .env local peut définir « false » pour le développement en http://).
        $prod = $this->configAvec('session.php', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => '', 'SESSION_ENCRYPT' => '']);

        $this->assertTrue($prod['secure']);
        $this->assertTrue($prod['encrypt']);
        $this->assertTrue($prod['http_only']);
        $this->assertContains($prod['same_site'], ['lax', 'strict']);
    }

    public function test_la_redirection_https_est_active_par_defaut_derriere_un_proxy_de_confiance(): void
    {
        $prod = $this->configAvec('koudmain.php', ['APP_ENV' => 'production', 'TRUST_PROXY' => 'true']);
        $this->assertTrue($prod['securite']['forcer_https']);

        $sansProxy = $this->configAvec('koudmain.php', ['APP_ENV' => 'production', 'TRUST_PROXY' => 'false']);
        $this->assertFalse($sansProxy['securite']['forcer_https'], 'Sans TRUST_PROXY, la redirection tournerait en boucle : elle reste coupée.');
        $this->assertGreaterThanOrEqual(31_536_000, $prod['securite']['hsts_secondes']);
    }

    // ------------------------------------------------------------------ Règle 13 : CORS

    public function test_aucun_site_tiers_n_a_le_droit_d_appeler_l_application_par_defaut(): void
    {
        $prealable = $this->call('OPTIONS', '/connexion', [], [], [], [
            'HTTP_ORIGIN' => 'https://pirate.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertFalse($prealable->headers->has('Access-Control-Allow-Origin'));

        $reponse = $this->withHeader('Origin', 'https://pirate.example')->get('/connexion');
        $this->assertFalse($reponse->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($reponse->headers->has('Access-Control-Allow-Credentials'));

        $reponse = $this->withHeader('Origin', '*')->post('/connexion', ['email' => 'a@b.ci', 'password' => 'x']);
        $this->assertFalse($reponse->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_la_liste_blanche_cors_refuse_le_joker_et_les_origines_non_https(): void
    {
        $prod = $this->configAvec('cors.php', [
            'APP_ENV' => 'production',
            'CORS_ALLOWED_ORIGINS' => 'https://partenaire.example, *, http://pirate.example, https://app.example:8443, https://*.example, http://localhost:3000, null',
        ]);

        $this->assertSame(['https://partenaire.example', 'https://app.example:8443'], $prod['allowed_origins']);
        $this->assertNotContains('*', $prod['allowed_origins']);
        $this->assertFalse($prod['supports_credentials']);
        $this->assertNotEmpty($prod['paths']);
    }

    public function test_sans_origine_configuree_aucun_chemin_n_est_ouvert(): void
    {
        $vide = $this->configAvec('cors.php', ['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => '*']);

        $this->assertSame([], $vide['allowed_origins']);
        $this->assertSame([], $vide['paths']);
    }

    public function test_http_localhost_n_est_tolere_qu_hors_production(): void
    {
        $dev = $this->configAvec('cors.php', ['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:5173']);
        $this->assertSame(['http://localhost:5173'], $dev['allowed_origins']);

        $prod = $this->configAvec('cors.php', ['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:5173']);
        $this->assertSame([], $prod['allowed_origins']);
    }

    // ------------------------------------------------------------------ Règle 14 : erreurs

    public function test_le_mode_debogage_est_force_a_false_en_production_meme_si_la_variable_est_restee_a_true(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_une_erreur_interne_ne_montre_ni_le_message_ni_le_code_ni_les_chemins(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/explose', fn () => throw new \RuntimeException('DETAIL-SECRET mot de passe=abc /var/www/html/app/Secret.php'));

        $reponse = $this->get('/_test/explose')->assertStatus(500);
        $html = $reponse->getContent();

        foreach (['DETAIL-SECRET', 'RuntimeException', '/var/www', 'Secret.php', 'Stack trace', 'vendor/laravel', 'mot de passe=abc'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $html);
        }
    }

    public function test_une_erreur_interne_en_json_reste_generique(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/explose-json', fn () => throw new \RuntimeException('DETAIL-SECRET'));

        $reponse = $this->getJson('/_test/explose-json')->assertStatus(500);

        $this->assertSame(['message' => 'Server Error'], $reponse->json());
    }

    public function test_une_erreur_de_base_de_donnees_ne_revele_ni_la_requete_ni_les_identifiants(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/sql', fn () => \DB::select('select * from table_qui_n_existe_pas_secret'));

        $reponse = $this->get('/_test/sql')->assertStatus(500);

        foreach (['table_qui_n_existe_pas_secret', 'SQLSTATE', 'pgsql', '127.0.0.1', 'select *'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $reponse->getContent());
        }
    }

    public function test_les_pages_d_erreur_ne_revelent_pas_la_technologie_du_serveur(): void
    {
        config(['app.debug' => false]);

        foreach ([$this->get('/introuvable-xyz'), $this->get('/admin')] as $reponse) {
            $html = $reponse->getContent();
            $this->assertStringNotContainsString('Symfony', $html);
            $this->assertStringNotContainsString('Whoops', $html);
            $this->assertStringNotContainsString('Illuminate\\', $html);
            $this->assertFalse($reponse->headers->has('X-Powered-By'));
            $this->assertFalse($reponse->headers->has('Server'));
        }

        $ini = file_get_contents(base_path('docker/php/koudmain.ini'));
        $apache = file_get_contents(base_path('docker/apache/koudmain.conf'));
        $this->assertMatchesRegularExpression('/^display_errors\s*=\s*Off/m', $ini);
        $this->assertMatchesRegularExpression('/^expose_php\s*=\s*Off/m', $ini);
        $this->assertStringContainsString('ServerTokens Prod', $apache);
        $this->assertStringContainsString('ServerSignature Off', $apache);
    }

    public function test_un_mot_de_passe_n_est_jamais_rejoue_dans_un_formulaire_apres_une_erreur(): void
    {
        $this->creerQuartier();

        $this->from('/inscription')->post('/inscription', [
            'role' => 'client', 'prenom' => 'A', 'nom' => 'B', 'email' => 'pas-un-email', 'telephone' => '1',
            'password' => 'Secret-a-ne-pas-rejouer1', 'password_confirmation' => 'Secret-a-ne-pas-rejouer1',
        ])->assertSessionHasErrors();

        $ancien = session()->getOldInput();
        $this->assertArrayNotHasKey('password', $ancien);
        $this->assertArrayNotHasKey('password_confirmation', $ancien);
        $this->assertSame('pas-un-email', $ancien['email'] ?? null);
    }
}
