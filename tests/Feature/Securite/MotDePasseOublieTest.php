<?php

namespace Tests\Feature\Securite;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\MotDePasseOublieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Règles 16 et 19 : « mot de passe oublié » réutilise le même mécanisme de lien signé que la confirmation
 * d'adresse (voir ConfirmationEmailTest), et ne révèle jamais si une adresse est inscrite.
 */
class MotDePasseOublieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    private function compte(array $attributs = []): User
    {
        return User::factory()->create($attributs + ['email' => 'connu@exemple.ci']);
    }

    public function test_la_demande_envoie_un_lien_signe_a_l_adresse_saisie(): void
    {
        Mail::fake();
        $this->compte();

        $this->post('/mot-de-passe-oublie', ['email' => 'connu@exemple.ci'])->assertRedirect(route('connexion'));

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            $this->assertTrue($mail->hasTo('connu@exemple.ci'));
            $this->assertStringContainsString('/mot-de-passe-oublie/', $mail->lien);
            $this->assertStringContainsString('signature=', $mail->lien);

            return true;
        });
    }

    public function test_la_demande_repond_pareil_que_le_compte_existe_ou_non(): void
    {
        Mail::fake();
        $this->compte();

        $reponses = [];

        foreach (['connu@exemple.ci', 'inconnu@exemple.ci'] as $adresse) {
            $reponse = $this->post('/mot-de-passe-oublie', ['email' => $adresse])->assertRedirect(route('connexion'));
            $reponses[] = session('succes');
            $reponse->assertSessionHasNoErrors();
        }

        $this->assertCount(1, array_unique($reponses));
        Mail::assertSent(NotificationMail::class, 1); // seul le compte réellement inscrit reçoit un message
    }

    public function test_pas_de_lien_par_e_mail_vers_une_adresse_non_confirmee(): void
    {
        // Compte de l'application (numéro vérifié) : son mot de passe se réinitialise par SMS, pas par une adresse jamais confirmée.
        Mail::fake();
        User::factory()->unverified()->create(['email' => 'mobile@exemple.ci', 'telephone_verifie_at' => now()]);

        $this->post('/mot-de-passe-oublie', ['email' => 'mobile@exemple.ci'])->assertRedirect(route('connexion'));

        Mail::assertNothingSent();
    }

    public function test_le_lien_permet_de_choisir_un_nouveau_mot_de_passe_et_de_se_connecter_avec(): void
    {
        $utilisateur = $this->compte();
        $lien = app(MotDePasseOublieService::class)->lien($utilisateur);

        $this->get($lien)->assertOk();

        $this->post($lien, ['password' => 'Nouveaumdp1', 'password_confirmation' => 'Nouveaumdp1'])
            ->assertRedirect(route('connexion'))->assertSessionHas('succes');

        $this->assertGuest(); // le lien ne connecte personne

        // L'ancien mot de passe ("password", cf. UserFactory) ne fonctionne plus, le nouveau oui.
        $this->post('/connexion', ['email' => 'connu@exemple.ci', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/connexion', ['email' => 'connu@exemple.ci', 'password' => 'Nouveaumdp1'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_le_lien_ne_sert_plus_apres_avoir_deja_servi(): void
    {
        $utilisateur = $this->compte();
        $lien = app(MotDePasseOublieService::class)->lien($utilisateur);

        $this->post($lien, ['password' => 'Nouveaumdp1', 'password_confirmation' => 'Nouveaumdp1'])->assertRedirect(route('connexion'));

        // Rejouer exactement le même lien (même signature) : le mot de passe a changé, l'empreinte ne correspond plus.
        $this->get($lien)->assertForbidden();
        $this->post($lien, ['password' => 'Encoreunautre1', 'password_confirmation' => 'Encoreunautre1'])->assertForbidden();
    }

    public function test_un_lien_falsifie_est_refuse(): void
    {
        $utilisateur = $this->compte();
        $lien = app(MotDePasseOublieService::class)->lien($utilisateur);

        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $lien))->assertForbidden();
        $this->get('/mot-de-passe-oublie/'.$utilisateur->id.'/'.sha1('rien'))->assertForbidden(); // pas de signature du tout
        $this->assertTrue(Hash::check('password', $utilisateur->fresh()->password)); // mot de passe inchangé
    }

    public function test_un_lien_expire_est_refuse(): void
    {
        $utilisateur = $this->compte();
        $lien = app(MotDePasseOublieService::class)->lien($utilisateur);

        $this->travel(61)->minutes();

        $this->get($lien)->assertForbidden();
    }

    public function test_le_lien_d_un_compte_ne_reinitialise_pas_un_autre_compte(): void
    {
        // Mots de passe distincts et explicites : sans ça, la fabrique de test réutilise le même hachage
        // pour tous les comptes par défaut, ce qui fausserait ce scénario.
        $premier = $this->compte(['password' => Hash::make('Motdepasse1')]);
        $second = User::factory()->create(['email' => 'second@exemple.ci', 'password' => Hash::make('Motdepasse2')]);
        $lien = app(MotDePasseOublieService::class)->lien($premier);

        // On remplace l'identifiant dans l'adresse : la signature (donc l'empreinte) ne correspond plus.
        $lienDetourne = str_replace('/mot-de-passe-oublie/'.$premier->id.'/', '/mot-de-passe-oublie/'.$second->id.'/', $lien);

        $this->get($lienDetourne)->assertForbidden();
        $this->assertTrue(Hash::check('Motdepasse2', $second->fresh()->password));
    }

    public function test_la_demande_est_limitee_par_adresse(): void
    {
        Mail::fake();
        $this->compte();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/mot-de-passe-oublie', ['email' => 'connu@exemple.ci'])->assertRedirect(route('connexion'));
        }

        $this->post('/mot-de-passe-oublie', ['email' => 'connu@exemple.ci'])->assertStatus(429);
        Mail::assertSent(NotificationMail::class, 3);
    }

    public function test_les_liens_sont_limites_par_ip(): void
    {
        $utilisateur = $this->compte();

        for ($i = 0; $i < 20; $i++) {
            $this->get('/mot-de-passe-oublie/'.$utilisateur->id.'/'.str_repeat('a', 40));
        }

        $this->get('/mot-de-passe-oublie/'.$utilisateur->id.'/'.str_repeat('a', 40))->assertStatus(429);
    }
}
