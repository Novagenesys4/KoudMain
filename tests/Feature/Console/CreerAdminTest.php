<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreerAdminTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION_MDP = 'Mot de passe (8 caractères minimum, avec une lettre et un chiffre)';
    private const QUESTION_CONFIRMATION = 'Confirmez le mot de passe';

    public function test_cree_un_administrateur_avec_un_wallet(): void
    {
        $this->creerQuartier();

        $this->artisan('koudmain:creer-admin', ['email' => 'Chef@Exemple.ci'])
            ->expectsQuestion(self::QUESTION_MDP, 'Motdepasse1')
            ->expectsQuestion(self::QUESTION_CONFIRMATION, 'Motdepasse1')
            ->assertExitCode(0);

        $admin = User::where('email', 'chef@exemple.ci')->firstOrFail();

        $this->assertTrue($admin->est_admin);
        $this->assertTrue($admin->est_valide);
        $this->assertFalse($admin->est_client);
        $this->assertFalse($admin->est_prestataire);
        $this->assertTrue(Hash::check('Motdepasse1', $admin->password));
        $this->assertNotNull($admin->wallet);
    }

    public function test_refuse_un_e_mail_deja_utilise(): void
    {
        $this->creerQuartier();
        User::factory()->create(['email' => 'chef@exemple.ci']);

        $this->artisan('koudmain:creer-admin', ['email' => 'chef@exemple.ci'])->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_refuse_un_mot_de_passe_trop_faible(): void
    {
        $this->creerQuartier();

        $this->artisan('koudmain:creer-admin', ['email' => 'chef@exemple.ci'])
            ->expectsQuestion(self::QUESTION_MDP, 'court')
            ->expectsQuestion(self::QUESTION_CONFIRMATION, 'court')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuse_deux_mots_de_passe_differents(): void
    {
        $this->creerQuartier();

        $this->artisan('koudmain:creer-admin', ['email' => 'chef@exemple.ci'])
            ->expectsQuestion(self::QUESTION_MDP, 'Motdepasse1')
            ->expectsQuestion(self::QUESTION_CONFIRMATION, 'Motdepasse2')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demande_de_charger_la_geographie_si_la_base_n_a_aucun_quartier(): void
    {
        $this->artisan('koudmain:creer-admin', ['email' => 'chef@exemple.ci'])->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
