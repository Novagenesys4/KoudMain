<?php

namespace Tests\Feature\Espace;

use App\Models\Prestation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** « Mes prestations » : la grille du prestataire, sa recherche et sa pagination. */
class MesPrestationsTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private User $moi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moi = $this->unPrestataire();
        $this->moi->wallet()->create();
    }

    private function prestation(string $titre, string $service = 'Coiffure femme', ?User $de = null): Prestation
    {
        return Prestation::factory()->for($de ?? $this->moi, 'prestataire')->create(['titre' => $titre, 'service_id' => $this->unService($service)->id]);
    }

    public function test_l_etat_vide_propose_de_publier(): void
    {
        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertOk()->assertSee('Vous n\'avez pas encore de prestation');
    }

    public function test_seules_mes_prestations_apparaissent(): void
    {
        $this->prestation('Tresses africaines');
        $this->prestation('Offre d\'un concurrent', de: $this->unPrestataire());

        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertOk()->assertSee('Tresses africaines')->assertDontSee('concurrent');
    }

    public function test_les_prestations_masquees_sont_indiquees(): void
    {
        $this->prestation('Coupe homme')->forceFill(['est_active' => false])->save();

        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertSee('Masquée')->assertSee('Publier');
    }

    public function test_la_recherche_ignore_accents_casse_et_cherche_dans_le_service(): void
    {
        $this->prestation('Réparation de fuite', 'Plomberie');
        $this->prestation('Tresses africaines', 'Coiffure femme');

        $this->actingAs($this->moi)->get('/prestataire/prestations?q=REPARATION')->assertOk()->assertSee('Réparation de fuite')->assertDontSee('Tresses africaines');
        $this->actingAs($this->moi)->get('/prestataire/prestations?q=coiffure')->assertOk()->assertSee('Tresses africaines')->assertDontSee('Réparation de fuite');
        $this->actingAs($this->moi)->get('/prestataire/prestations?q=zzz')->assertOk()->assertSee('Aucune prestation ne correspond');
        $this->actingAs($this->moi)->get('/prestataire/prestations?q=%25')->assertOk()->assertSee('Aucune prestation ne correspond');
    }

    public function test_la_liste_est_paginee_par_neuf(): void
    {
        foreach (range(1, 11) as $i) {
            $this->prestation("Offre numéro $i");
        }

        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertOk()->assertSee('Page 1 sur 2')->assertDontSee('Offre numéro 1<', false);
        $this->actingAs($this->moi)->get('/prestataire/prestations?page=2')->assertOk()->assertSee('Page 2 sur 2');
    }

    public function test_la_note_moyenne_et_le_nombre_d_avis_s_affichent(): void
    {
        $prestation = $this->prestation('Coiffure soignée');
        $this->unAvis($prestation, 5);
        $this->unAvis($prestation, 4);

        $this->actingAs($this->moi)->get('/prestataire/prestations')->assertSee('4,5')->assertSee('(2)');
    }
}
