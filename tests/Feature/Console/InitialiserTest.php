<?php

namespace Tests\Feature\Console;

use App\Models\Categorie;
use App\Models\Quartier;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialiserTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_base_vide_recoit_les_donnees_de_reference(): void
    {
        $this->artisan('koudmain:initialiser')->assertSuccessful();

        $this->assertGreaterThan(0, Region::count());
        $this->assertGreaterThan(50, Quartier::count());
        $this->assertGreaterThan(5, Categorie::count());
    }

    public function test_une_base_deja_en_service_n_est_jamais_touchee(): void
    {
        $this->artisan('koudmain:initialiser')->assertSuccessful();
        Categorie::where('nom', 'Jardinage')->delete();
        $categories = Categorie::count();
        $quartiers = Quartier::count();

        $this->artisan('koudmain:initialiser')->assertSuccessful();

        $this->assertSame($categories, Categorie::count(), 'une catégorie supprimée par l\'administrateur ne revient pas');
        $this->assertSame($quartiers, Quartier::count());
        $this->assertFalse(Categorie::where('nom', 'Jardinage')->exists());
    }
}
