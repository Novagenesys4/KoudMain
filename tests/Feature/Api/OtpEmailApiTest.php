<?php

namespace Tests\Feature\Api;

use App\Mail\CodeVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** SMS_DRIVER=email : les codes partent par e-mail, prouvent l'ADRESSE (jamais le numéro) et suffisent pour commander. */
class OtpEmailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['koudmain.securite.confirmation_email' => true, 'koudmain.api.sms.driver' => 'email']);
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

    public function test_inscription_code_par_email_confirme_l_adresse_pas_le_numero(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', $this->inscription())
            ->assertStatus(202)
            ->assertJsonPath('data.canal', 'email')
            ->assertJsonPath('data.email_masque', 'aw•••@example.ci');

        Mail::assertSent(CodeVerificationMail::class, fn (CodeVerificationMail $m) => $m->hasTo('awa@example.ci') && $m->code === $reponse->json('data.code_debug'));

        $this->postJson('/api/v1/auth/register/verify', [
            'verification_id' => $reponse->json('data.verification_id'),
            'code' => $reponse->json('data.code_debug'),
        ])->assertOk()
            ->assertJsonPath('data.user.email_confirme', true)
            ->assertJsonPath('data.user.telephone_verifie', false)
            ->assertJsonPath('data.user.compte_verifie', true);

        $user = User::query()->where('email', 'awa@example.ci')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->telephone_verifie_at);
    }

    public function test_connexion_par_numero_ou_email_apres_verification_par_email(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', $this->inscription());
        $this->postJson('/api/v1/auth/register/verify', ['verification_id' => $reponse->json('data.verification_id'), 'code' => $reponse->json('data.code_debug')])->assertOk();

        $this->postJson('/api/v1/auth/login', ['login' => 'awa@example.ci', 'password' => 'koudmain1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => '0708123456', 'password' => 'koudmain1'])->assertOk();
    }

    public function test_compte_sans_identifiant_verifie_refuse(): void
    {
        // Ni numéro ni e-mail vérifiés : le jeton ne sert à rien (middleware api.compte), quel que soit le canal.
        $user = User::factory()->unverified()->create(['telephone_verifie_at' => null, 'quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertStatus(403)->assertJsonPath('code', 'compte_non_verifie');
    }

    public function test_email_confirme_suffit_pour_commander_et_aucun_code_a_redemander(): void
    {
        // Compte du site : e-mail confirmé par lien, numéro jamais vérifié. Avec les codes par e-mail, c'est un compte vérifié.
        $user = User::factory()->create(['telephone_verifie_at' => null, 'quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.telephone_verifie', false)
            ->assertJsonPath('data.compte_verifie', true);

        $this->postJson('/api/v1/auth/phone/send-code')->assertStatus(422)->assertJsonPath('message', 'Votre compte est déjà vérifié.');
        Mail::assertNothingSent();
    }

    public function test_mot_de_passe_oublie_ne_revele_pas_l_adresse(): void
    {
        User::factory()->create(['email' => 'koffi@example.ci', 'telephone' => '0102030405', 'quartier_id' => $this->creerQuartier()->id]);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '0102030405'])
            ->assertStatus(202)->assertJsonPath('data.email_masque', null)->assertJsonPath('data.canal', 'email');

        Mail::assertSent(CodeVerificationMail::class, fn (CodeVerificationMail $m) => $m->hasTo('koffi@example.ci'));
    }
}
