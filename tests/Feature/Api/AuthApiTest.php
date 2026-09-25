<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\VerificationOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** API mobile : inscription avec code SMS, connexion par téléphone ou e-mail, jetons, mot de passe oublié. */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        config(['koudmain.securite.confirmation_email' => false, 'koudmain.api.sms.driver' => 'journal']);
    }

    /** @return array<string, mixed> */
    private function inscription(array $surcharge = []): array
    {
        return $surcharge + [
            'nom' => 'Diabaté', 'prenom' => 'Awa', 'email' => 'awa@example.ci', 'telephone' => '07 08 12 34 56',
            'password' => 'koudmain1', 'password_confirmation' => 'koudmain1', 'quartier_id' => $this->creerQuartier()->id,
            'role' => 'client', 'device_name' => 'Pixel 8',
        ];
    }

    public function test_inscription_puis_code_sms_donne_un_jeton(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', $this->inscription())
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.telephone_masque', '07 08 •• •• 56')
            ->assertJsonStructure(['success', 'message', 'data' => ['verification_id', 'expire_dans', 'renvoi_dans', 'code_debug']]);

        $user = User::query()->where('email', 'awa@example.ci')->firstOrFail();
        $this->assertNull($user->telephone_verifie_at);
        $this->assertTrue($user->est_client);

        $verif = $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $reponse->json('data.verification_id'),
            'code' => $reponse->json('data.code_debug'),
            'device_name' => 'Pixel 8',
        ])->assertOk()->assertJsonPath('data.user.telephone_verifie', true)->assertJsonPath('data.token_type', 'Bearer');

        $jeton = $verif->json('data.token');
        $this->assertMatchesRegularExpression('/^\d+\|km_[A-Za-z0-9]{40,}$/', $jeton); // préfixe « km_ » : reconnaissable par les scanners de secrets

        $this->withToken($jeton)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'awa@example.ci')->assertJsonPath('data.role', 'client');

        // Un code ne sert qu'une fois.
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $reponse->json('data.verification_id'), 'code' => $reponse->json('data.code_debug')])
            ->assertStatus(422)->assertJsonPath('code', 'operation_refusee');
    }

    public function test_une_adresse_deja_inscrite_recoit_la_meme_reponse_mais_aucun_code_ne_la_valide(): void
    {
        User::factory()->create(['email' => 'awa@example.ci', 'quartier_id' => $this->creerQuartier()->id]);

        $reponse = $this->postJson('/api/v1/auth/register', $this->inscription())
            ->assertStatus(202)->assertJsonPath('success', true)->assertJsonPath('data.telephone_masque', '07 08 •• •• 56');

        $this->assertSame(1, User::query()->where('email', 'awa@example.ci')->count());
        $this->assertArrayNotHasKey('code_debug', $reponse->json('data'));

        foreach (['000000', '123456'] as $code) {
            $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $reponse->json('data.verification_id'), 'code' => $code])
                ->assertStatus(422)->assertJsonPath('message', 'Code incorrect. Vérifiez le SMS et réessayez.');
        }
    }

    public function test_cinq_mauvais_codes_bloquent_la_demande(): void
    {
        $id = $this->postJson('/api/v1/auth/register', $this->inscription())->json('data.verification_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => '999999'])->assertStatus(422);
        }

        $bon = VerificationOtp::query()->findOrFail($id);
        $this->assertSame(5, $bon->tentatives);
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $id, 'code' => '111111'])
            ->assertStatus(422)->assertJsonPath('message', 'Trop d\'essais avec ce code. Demandez un nouveau code.');
    }

    public function test_renvoi_du_code_seulement_apres_le_delai(): void
    {
        $id = $this->postJson('/api/v1/auth/register', $this->inscription())->json('data.verification_id');

        $this->postJson('/api/v1/auth/otp/resend', ['verification_id' => $id])->assertStatus(422)->assertJsonPath('code', 'operation_refusee');

        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/otp/resend', ['verification_id' => $id])->assertOk()->assertJsonStructure(['data' => ['code_debug']]);
    }

    public function test_validation_de_l_inscription_en_json_422(): void
    {
        $this->postJson('/api/v1/auth/register', $this->inscription(['telephone' => '123', 'password_confirmation' => 'autre']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'validation')
            ->assertJsonStructure(['errors' => ['telephone', 'password']]);
    }

    public function test_sans_fournisseur_sms_l_inscription_est_indisponible(): void
    {
        config(['koudmain.api.sms.driver' => 'aucun']);

        $this->postJson('/api/v1/auth/register', $this->inscription())->assertStatus(503)->assertJsonPath('code', 'sms_indisponible');
        $this->assertDatabaseMissing('users', ['email' => 'awa@example.ci']);
    }

    public function test_connexion_par_telephone_ou_email(): void
    {
        $user = User::factory()->create(['telephone' => '0708123456', 'email' => 'koffi@example.ci', 'quartier_id' => $this->creerQuartier()->id]);

        $this->postJson('/api/v1/auth/login', ['login' => '07 08 12 34 56', 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.user.telephone_verifie', false);
        $this->postJson('/api/v1/auth/login', ['login' => 'KOFFI@example.ci', 'password' => 'password'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['login' => '0708123456', 'password' => 'mauvais'])
            ->assertStatus(422)->assertJsonPath('code', 'validation')->assertJsonPath('message', 'Identifiant ou mot de passe incorrect.');
        $this->postJson('/api/v1/auth/login', ['login' => 'inconnu@example.ci', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('message', 'Identifiant ou mot de passe incorrect.');
    }

    public function test_un_numero_partage_par_deux_comptes_ne_connecte_personne(): void
    {
        User::factory()->count(2)->create(['telephone' => '0501020304', 'quartier_id' => $this->creerQuartier()->id]);

        $this->postJson('/api/v1/auth/login', ['login' => '0501020304', 'password' => 'password'])->assertStatus(422);
    }

    public function test_administrateur_refuse_et_compte_sans_identifiant_verifie_refuse(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->postJson('/api/v1/auth/login', ['login' => $admin->email, 'password' => 'password'])->assertStatus(403)->assertJsonPath('code', 'admin_non_autorise');

        config(['koudmain.securite.confirmation_email' => true]);
        // Ni e-mail confirmé, ni numéro vérifié : aucune porte d'entrée.
        $nouveau = User::factory()->unverified()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->postJson('/api/v1/auth/login', ['login' => $nouveau->email, 'password' => 'password'])->assertStatus(403)->assertJsonPath('code', 'identifiant_non_verifie');

        // Filet de sécurité : un jeton existant ne sert plus sans identifiant vérifié.
        $this->withToken($nouveau->createToken('x')->plainTextToken)->getJson('/api/v1/auth/me')->assertStatus(403)->assertJsonPath('code', 'compte_non_verifie');
    }

    // ------------------------------------------------------------ E-mail facultatif : le numéro vérifié suffit

    public function test_inscription_l_email_non_confirme_n_empeche_pas_d_acceder_a_son_espace(): void
    {
        config(['koudmain.securite.confirmation_email' => true]);

        $demande = $this->postJson('/api/v1/auth/register', $this->inscription())->assertStatus(202);
        $verif = $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $demande->json('data.verification_id'), 'code' => $demande->json('data.code_debug'),
        ])->assertOk()
            ->assertJsonPath('data.email_a_confirmer', true)
            ->assertJsonPath('data.user.email_confirme', false)
            ->assertJsonPath('data.user.telephone_verifie', true);

        $this->assertNotNull($verif->json('data.token'));
        $this->withToken($verif->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_tant_que_l_email_n_est_pas_confirme_on_se_connecte_avec_son_numero(): void
    {
        config(['koudmain.securite.confirmation_email' => true]);
        $user = User::factory()->unverified()->create([
            'telephone' => '0708123456', 'email' => 'awa@example.ci', 'telephone_verifie_at' => now(), 'quartier_id' => $this->creerQuartier()->id,
        ]);

        $this->postJson('/api/v1/auth/login', ['login' => '07 08 12 34 56', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/auth/login', ['login' => 'awa@example.ci', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('code', 'identifiant_non_verifie');

        // Une fois l'adresse confirmée (compte certifié), elle sert aussi d'identifiant.
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/v1/auth/login', ['login' => 'awa@example.ci', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.email_confirme', true);
    }

    public function test_un_numero_deja_verifie_sur_un_autre_compte_est_refuse_et_l_inscription_annulee(): void
    {
        User::factory()->create(['telephone' => '0708123456', 'telephone_verifie_at' => now(), 'quartier_id' => $this->creerQuartier()->id]);

        $demande = $this->postJson('/api/v1/auth/register', $this->inscription(['email' => 'autre@example.ci']))->assertStatus(202);
        $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $demande->json('data.verification_id'), 'code' => $demande->json('data.code_debug'),
        ])->assertStatus(409)->assertJsonPath('code', 'telephone_deja_utilise');

        // Le compte inachevé est retiré : l'adresse saisie reste libre pour une nouvelle inscription.
        $this->assertDatabaseMissing('users', ['email' => 'autre@example.ci']);
    }

    public function test_le_numero_verifie_designe_son_compte_meme_partage(): void
    {
        $quartier = $this->creerQuartier()->id;
        User::factory()->create(['telephone' => '0501020304', 'quartier_id' => $quartier]);
        $verifie = User::factory()->create(['telephone' => '0501020304', 'telephone_verifie_at' => now(), 'quartier_id' => $quartier]);

        $this->postJson('/api/v1/auth/login', ['login' => '0501020304', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.id', $verifie->id);
    }

    public function test_renvoi_du_lien_de_confirmation_depuis_l_application(): void
    {
        config(['koudmain.securite.confirmation_email' => true]);
        $user = User::factory()->unverified()->create(['telephone_verifie_at' => now(), 'quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/send-link')->assertOk()->assertJsonPath('success', true);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/v1/auth/email/send-link')->assertStatus(422)->assertJsonPath('code', 'operation_refusee');

        config(['koudmain.securite.confirmation_email' => false]);
        Sanctum::actingAs(User::factory()->unverified()->create(['telephone_verifie_at' => now(), 'quartier_id' => $this->creerQuartier()->id]));
        $this->postJson('/api/v1/auth/email/send-link')->assertStatus(503)->assertJsonPath('code', 'email_indisponible');
    }

    public function test_prestataire_en_attente_se_connecte_mais_ne_peut_pas_agir(): void
    {
        $pro = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id, 'telephone_verifie_at' => now()]);

        $this->postJson('/api/v1/auth/login', ['login' => $pro->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.role', 'prestataire')->assertJsonPath('data.user.statut_compte', 'en_attente_validation');

        Sanctum::actingAs($pro);
        $this->postJson('/api/v1/wallet/retraits', ['montant' => 5000, 'methode' => 'Wave', 'destination' => '0701020304'])
            ->assertStatus(403)->assertJsonPath('code', 'prestataire_non_valide');
    }

    public function test_deconnexion_supprime_le_jeton(): void
    {
        $user = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $jeton = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])->json('data.token');

        $this->withToken($jeton)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'non_authentifie');
    }

    public function test_sans_jeton_401_json_meme_sans_accept(): void
    {
        $this->get('/api/v1/auth/me')->assertStatus(401)->assertJson(['success' => false, 'data' => null, 'code' => 'non_authentifie']);
        $this->get('/api/v1/nexiste-pas')->assertStatus(404)->assertJsonPath('code', 'introuvable');
    }

    public function test_verification_du_numero_d_un_compte_existant(): void
    {
        $user = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($user);

        $demande = $this->postJson('/api/v1/auth/phone/send-code')->assertStatus(202);
        $this->postJson('/api/v1/auth/phone/verify', ['verification_id' => $demande->json('data.verification_id'), 'code' => $demande->json('data.code_debug')])
            ->assertOk()->assertJsonPath('data.telephone_verifie', true);

        $this->postJson('/api/v1/auth/phone/send-code')->assertStatus(422)->assertJsonPath('message', 'Votre numéro est déjà vérifié.');
    }

    public function test_le_code_d_un_autre_compte_ne_verifie_pas_mon_numero(): void
    {
        $autre = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($autre);
        $demande = $this->postJson('/api/v1/auth/phone/send-code');

        Sanctum::actingAs(User::factory()->create(['quartier_id' => $this->creerQuartier()->id]));
        $this->postJson('/api/v1/auth/phone/verify', ['verification_id' => $demande->json('data.verification_id'), 'code' => $demande->json('data.code_debug')])
            ->assertStatus(422);
    }

    public function test_mot_de_passe_oublie_par_sms(): void
    {
        $user = User::factory()->create(['telephone' => '0102030405', 'quartier_id' => $this->creerQuartier()->id]);
        $ancien = $user->createToken('ancien')->plainTextToken;

        $demande = $this->postJson('/api/v1/auth/password/forgot', ['login' => '01 02 03 04 05'])
            ->assertStatus(202)->assertJsonPath('data.telephone_masque', null);

        $this->postJson('/api/v1/auth/password/reset', [
            'verification_id' => $demande->json('data.verification_id'), 'code' => $demande->json('data.code_debug'),
            'password' => 'nouveau123', 'password_confirmation' => 'nouveau123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->withToken($ancien)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['login' => '0102030405', 'password' => 'nouveau123'])->assertOk();
    }

    public function test_mot_de_passe_oublie_compte_inconnu_meme_reponse(): void
    {
        $reponse = $this->postJson('/api/v1/auth/password/forgot', ['login' => 'personne@example.ci'])->assertStatus(202);
        $this->assertSame(
            ['verification_id', 'canal', 'telephone_masque', 'email_masque', 'longueur', 'expire_dans', 'renvoi_dans'],
            array_keys($reponse->json('data')),
        );
    }
}
