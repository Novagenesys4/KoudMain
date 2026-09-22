<?php

namespace Tests\Feature\Auth;

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InscriptionTest extends TestCase
{
    use RefreshDatabase;

    private int $quartierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quartierId = $this->creerQuartier()->id;
    }

    /** @param array<string, mixed> $surcharge */
    private function donnees(array $surcharge = []): array
    {
        return array_merge([
            'role' => 'client',
            'prenom' => 'Aya',
            'nom' => 'Koné',
            'email' => 'Aya.Kone@Exemple.ci',
            'telephone' => '07 12 34 56 78',
            'quartier_id' => $this->quartierId,
            'password' => 'Motdepasse1',
            'password_confirmation' => 'Motdepasse1',
        ], $surcharge);
    }

    public function test_la_page_d_inscription_s_affiche(): void
    {
        $this->get('/inscription')->assertOk()->assertSee('Riviera 2');
    }

    public function test_un_client_peut_s_inscrire_mais_son_adresse_reste_a_confirmer(): void
    {
        $this->post('/inscription', $this->donnees())
            ->assertRedirect(route('connexion'))
            ->assertSessionHas('succes');

        $user = User::where('email', 'aya.kone@exemple.ci')->firstOrFail(); // e-mail enregistré en minuscules

        $this->assertTrue($user->est_client);
        $this->assertFalse($user->est_prestataire);
        $this->assertFalse($user->est_admin);
        $this->assertTrue($user->est_valide);
        $this->assertNull($user->email_verified_at); // règle 19 : rien n'est actif tant que le lien reçu par e-mail n'est pas ouvert
        $this->assertSame('0712345678', $user->telephone);
        $this->assertNotSame('Motdepasse1', $user->password);
        $this->assertTrue(Hash::check('Motdepasse1', $user->password));
        $this->assertNotNull($user->wallet);
        $this->assertSame('0.00', $user->wallet->solde);
    }

    public function test_un_prestataire_attend_la_validation_d_un_administrateur(): void
    {
        $this->post('/inscription', $this->donnees(['role' => 'prestataire']))
            ->assertRedirect(route('connexion'));

        $user = User::where('email', 'aya.kone@exemple.ci')->firstOrFail();

        $this->assertTrue($user->est_prestataire);
        $this->assertFalse($user->est_client);
        $this->assertFalse($user->est_valide);
        $this->assertTrue($user->enAttenteValidation());
    }

    public function test_on_ne_peut_pas_s_inscrire_administrateur(): void
    {
        $this->from('/inscription')
            ->post('/inscription', $this->donnees(['role' => 'admin']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_un_champ_cache_ne_peut_pas_donner_de_droits(): void
    {
        // "Mass assignment" : un visiteur ajoute des champs au formulaire pour s'auto-promouvoir.
        $this->post('/inscription', $this->donnees(['est_admin' => 1, 'est_valide' => 1, 'est_prestataire' => 1]));

        $user = User::where('email', 'aya.kone@exemple.ci')->firstOrFail();

        $this->assertFalse($user->est_admin);
        $this->assertFalse($user->est_prestataire);
    }

    /** Règle 16 : la page ne révèle pas qu'une adresse est déjà inscrite (réponse identique), même avec une autre casse. */
    public function test_un_e_mail_deja_utilise_recoit_la_meme_reponse_qu_une_vraie_inscription(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'aya.kone@exemple.ci']);

        $this->from('/inscription')
            ->post('/inscription', $this->donnees(['email' => 'AYA.KONE@EXEMPLE.CI']))
            ->assertRedirect(route('connexion'))
            ->assertSessionHas('succes')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('aya.kone@exemple.ci')); // c'est le propriétaire qui est prévenu
    }

    public function test_les_formats_de_telephone_ivoiriens_sont_acceptes_et_normalises(): void
    {
        $this->post('/inscription', $this->donnees(['telephone' => '+225 05-12-34-56-78']))->assertRedirect(route('connexion'));

        $this->assertSame('0512345678', User::firstOrFail()->telephone);
    }

    #[DataProvider('champsInvalides')]
    public function test_les_champs_invalides_sont_refuses_avec_le_message_attendu(string $champ, mixed $valeur, string $message): void
    {
        $this->from('/inscription')
            ->post('/inscription', $this->donnees([$champ => $valeur]))
            ->assertSessionHasErrors([$champ => $message]);

        $this->assertDatabaseCount('users', 0);
    }

    public static function champsInvalides(): array
    {
        $mdp = 'Choisissez un mot de passe de 8 caractères minimum, avec au moins une lettre et un chiffre.';

        return [
            'nom vide' => ['nom', '', 'Indiquez votre nom (2 à 50 lettres).'],
            'nom avec chiffres' => ['nom', 'K0né', 'Indiquez votre nom (2 à 50 lettres).'],
            'prénom trop court' => ['prenom', 'A', 'Indiquez votre prénom (2 à 100 lettres).'],
            'e-mail invalide' => ['email', 'pas-un-email', 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.'],
            'téléphone étranger' => ['telephone', '0612345678', 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.'],
            'téléphone trop court' => ['telephone', '071234', 'Saisissez un numéro à 10 chiffres commençant par 01, 05, 07, 21, 25 ou 27.'],
            'mot de passe trop court' => ['password', 'Ab1', $mdp],
            'mot de passe sans chiffre' => ['password', 'Motdepasseseul', $mdp],
            'mot de passe sans lettre' => ['password', '123456789', $mdp],
            'mot de passe trop long (limite bcrypt)' => ['password', str_repeat('a1', 37), $mdp],
            'quartier inexistant' => ['quartier_id', 999999, 'Choisissez votre quartier dans la liste.'],
            'type de compte absent' => ['role', '', 'Choisissez un type de compte.'],
        ];
    }

    public function test_la_confirmation_du_mot_de_passe_doit_etre_identique(): void
    {
        $this->from('/inscription')
            ->post('/inscription', $this->donnees(['password_confirmation' => 'Autremotdepasse1']))
            ->assertSessionHasErrors(['password' => 'Les deux mots de passe ne sont pas identiques.']);
    }

    public function test_l_inscription_est_limitee_par_ip(): void
    {
        // 8 tentatives autorisées par tranche de 15 minutes ; la 9e est bloquée, même avec des données valides.
        for ($i = 0; $i < 8; $i++) {
            $this->post('/inscription', $this->donnees(['email' => "invalide$i"]));
        }

        $this->from('/inscription')
            ->post('/inscription', $this->donnees(['email' => 'valide@exemple.ci']))
            ->assertSessionHasErrors(['email' => "Trop de tentatives d'inscription. Réessayez dans 15 minutes."]);

        $this->assertDatabaseMissing('users', ['email' => 'valide@exemple.ci']);
    }

    public function test_un_utilisateur_connecte_est_renvoye_vers_son_espace(): void
    {
        $this->creerQuartier();

        $this->actingAs(User::factory()->create())
            ->get('/inscription')
            ->assertRedirect(route('client.tableau-de-bord'));
    }
}
