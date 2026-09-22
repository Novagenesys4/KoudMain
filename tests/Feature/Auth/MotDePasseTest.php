<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        $this->user = User::factory()->admin()->create(['email' => 'admin@exemple.ci']);
    }

    private function modifier(array $donnees)
    {
        return $this->actingAs($this->user)->from('/compte/mot-de-passe')->put('/compte/mot-de-passe', $donnees);
    }

    public function test_la_page_est_reservee_aux_utilisateurs_connectes(): void
    {
        $this->get('/compte/mot-de-passe')->assertRedirect(route('connexion'));
        $this->actingAs($this->user)->get('/compte/mot-de-passe')->assertOk();
    }

    public function test_le_mot_de_passe_peut_etre_change(): void
    {
        $this->modifier([
            'mot_de_passe_actuel' => 'password',
            'password' => 'Nouveaumdp2026',
            'password_confirmation' => 'Nouveaumdp2026',
        ])->assertRedirect('/compte/mot-de-passe')->assertSessionHas('succes');

        $this->assertTrue(Hash::check('Nouveaumdp2026', $this->user->fresh()->password));
        $this->assertFalse(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_le_mot_de_passe_actuel_doit_etre_correct(): void
    {
        $this->modifier([
            'mot_de_passe_actuel' => 'faux',
            'password' => 'Nouveaumdp2026',
            'password_confirmation' => 'Nouveaumdp2026',
        ])->assertSessionHasErrors(['mot_de_passe_actuel' => 'Le mot de passe actuel est incorrect.']);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_le_nouveau_mot_de_passe_doit_etre_different_et_solide(): void
    {
        $this->modifier([
            'mot_de_passe_actuel' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->modifier([
            'mot_de_passe_actuel' => 'password',
            'password' => 'courtcourt',
            'password_confirmation' => 'courtcourt',
        ])->assertSessionHasErrors('password');
    }
}
