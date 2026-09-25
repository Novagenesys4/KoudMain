<?php

namespace Tests\Feature\Securite;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\ConfirmationEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Règles 16 et 19 : adresse confirmée avant activation, et jamais de réponse qui révèle si une adresse est inscrite. */
class ConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    private int $quartierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quartierId = $this->creerQuartier()->id;
    }

    private function donnees(string $email = 'nouveau@exemple.ci', string $role = 'client'): array
    {
        return [
            'role' => $role, 'prenom' => 'Aya', 'nom' => 'Koné', 'email' => $email, 'telephone' => '0712345678',
            'quartier_id' => $this->quartierId, 'password' => 'Motdepasse1', 'password_confirmation' => 'Motdepasse1',
        ];
    }

    private function nonConfirme(array $attributs = []): User
    {
        return User::factory()->unverified()->create($attributs + ['email' => 'attente@exemple.ci']);
    }

    public function test_l_inscription_envoie_un_lien_de_confirmation_signe_a_l_adresse_saisie(): void
    {
        Mail::fake();

        $this->post('/inscription', $this->donnees())->assertRedirect(route('connexion'));

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            $this->assertTrue($mail->hasTo('nouveau@exemple.ci'));
            $this->assertStringContainsString('/email/confirmer/', $mail->lien);
            $this->assertStringContainsString('signature=', $mail->lien);

            return true;
        });
    }

    public function test_le_lien_n_utilise_jamais_l_en_tete_host_de_la_requete(): void
    {
        config(['app.url' => 'https://koudmain.example']);
        $utilisateur = $this->nonConfirme();

        $lien = app(ConfirmationEmailService::class)->lien($utilisateur);

        $this->assertStringStartsWith('https://koudmain.example/email/confirmer/', $lien);
    }

    public function test_un_compte_non_confirme_ne_peut_pas_se_connecter_meme_avec_le_bon_mot_de_passe(): void
    {
        $this->nonConfirme();

        $this->from('/connexion')->post('/connexion', ['email' => 'attente@exemple.ci', 'password' => 'password'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas('email_a_confirmer');

        $this->assertGuest();
    }

    public function test_le_lien_signe_confirme_l_adresse_puis_la_connexion_devient_possible(): void
    {
        Mail::fake();
        $utilisateur = $this->nonConfirme();

        $lien = app(ConfirmationEmailService::class)->lien($utilisateur);
        $this->get($lien)->assertRedirect(route('connexion'))->assertSessionHas('succes');

        $this->assertNotNull($utilisateur->fresh()->email_verified_at);
        $this->assertGuest(); // le lien confirme l'adresse, il ne connecte personne

        $this->post('/connexion', ['email' => 'attente@exemple.ci', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_un_lien_falsifie_est_refuse(): void
    {
        $utilisateur = $this->nonConfirme();
        $lien = app(ConfirmationEmailService::class)->lien($utilisateur);

        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $lien))->assertForbidden();
        $this->get('/email/confirmer/'.$utilisateur->id.'/'.sha1('attente@exemple.ci'))->assertForbidden(); // pas de signature du tout
        $this->assertNull($utilisateur->fresh()->email_verified_at);
    }

    public function test_un_lien_expire_est_refuse(): void
    {
        $utilisateur = $this->nonConfirme();
        $lien = app(ConfirmationEmailService::class)->lien($utilisateur);

        $this->travel(49)->hours();

        $this->get($lien)->assertForbidden();
        $this->assertNull($utilisateur->fresh()->email_verified_at);
    }

    public function test_un_lien_ne_sert_plus_apres_un_changement_d_adresse(): void
    {
        $utilisateur = $this->nonConfirme();
        $lien = app(ConfirmationEmailService::class)->lien($utilisateur);

        $utilisateur->forceFill(['email' => 'autre@exemple.ci'])->save();

        $this->get($lien)->assertForbidden();
        $this->assertNull($utilisateur->fresh()->email_verified_at);
    }

    public function test_le_lien_d_un_compte_ne_confirme_pas_un_autre_compte(): void
    {
        $premier = $this->nonConfirme();
        $second = $this->nonConfirme(['email' => 'second@exemple.ci']);
        $lien = app(ConfirmationEmailService::class)->lien($premier);

        // On remplace l'identifiant dans l'adresse : la signature ne correspond plus.
        $this->get(str_replace('/confirmer/'.$premier->id.'/', '/confirmer/'.$second->id.'/', $lien))->assertForbidden();
        $this->assertNull($second->fresh()->email_verified_at);
    }

    public function test_un_compte_dont_l_adresse_n_est_pas_confirmee_n_entre_pas_meme_avec_une_session_existante(): void
    {
        $utilisateur = $this->nonConfirme();

        $this->actingAs($utilisateur)->get(route('client.tableau-de-bord'))->assertRedirect(route('connexion'));
        $this->assertGuest();
    }

    public function test_le_renvoi_repond_pareil_que_le_compte_existe_ou_non(): void
    {
        Mail::fake();
        $this->nonConfirme();
        User::factory()->create(['email' => 'confirme@exemple.ci']);

        $reponses = [];

        foreach (['attente@exemple.ci', 'confirme@exemple.ci', 'inconnu@exemple.ci'] as $adresse) {
            $reponse = $this->post('/email/renvoyer', ['email' => $adresse])->assertRedirect(route('connexion'));
            $reponses[] = session('succes');
            $reponse->assertSessionHasNoErrors();
        }

        $this->assertCount(1, array_unique($reponses));
        Mail::assertSent(NotificationMail::class, 1); // seul le compte réellement en attente reçoit un message
    }

    public function test_un_compte_de_l_application_ne_recoit_pas_le_lien_a_la_demande_d_un_tiers(): void
    {
        // Compte actif par son numéro (application), adresse pas encore confirmée : ni le formulaire public de renvoi, ni une
        // inscription avec la même adresse n'envoient le lien (sinon le lecteur de cette boîte pourrait prendre le compte).
        Mail::fake();
        $this->nonConfirme(['telephone_verifie_at' => now()]);

        $this->post('/email/renvoyer', ['email' => 'attente@exemple.ci'])->assertRedirect(route('connexion'));
        $this->post('/inscription', $this->donnees('attente@exemple.ci'))->assertRedirect(route('connexion'));

        Mail::assertNothingSent();
    }

    public function test_confirmer_l_adresse_d_un_compte_de_l_application_le_certifie(): void
    {
        $compte = $this->nonConfirme(['telephone_verifie_at' => now()]);

        $this->get(app(ConfirmationEmailService::class)->lien($compte))
            ->assertRedirect(route('connexion'))
            ->assertSessionHas('succes', fn (string $message) => str_contains($message, 'certifié'));

        $this->assertTrue($compte->refresh()->emailConfirme());
    }

    public function test_le_renvoi_est_limite_par_adresse(): void
    {
        Mail::fake();
        $this->nonConfirme();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/email/renvoyer', ['email' => 'attente@exemple.ci'])->assertRedirect(route('connexion'));
        }

        $this->post('/email/renvoyer', ['email' => 'attente@exemple.ci'])->assertStatus(429);
        Mail::assertSent(NotificationMail::class, 3);
    }

    public function test_confirmer_un_prestataire_l_envoie_en_attente_de_validation_sans_l_activer(): void
    {
        Mail::fake();
        $this->post('/inscription', $this->donnees('presta@exemple.ci', 'prestataire'));

        $compte = User::where('email', 'presta@exemple.ci')->firstOrFail();
        $this->get(app(ConfirmationEmailService::class)->lien($compte))->assertRedirect(route('connexion'));

        $compte->refresh();
        $this->assertNotNull($compte->email_verified_at);
        $this->assertTrue($compte->enAttenteValidation()); // la validation par un administrateur reste nécessaire
        $this->post('/connexion', ['email' => 'presta@exemple.ci', 'password' => 'Motdepasse1']);
        $this->assertGuest();
    }

    public function test_les_liens_de_confirmation_sont_limites_par_ip(): void
    {
        $this->nonConfirme();

        for ($i = 0; $i < 20; $i++) {
            $this->get('/email/confirmer/1/'.str_repeat('a', 40));
        }

        $this->get('/email/confirmer/1/'.str_repeat('a', 40))->assertStatus(429);
    }
}
