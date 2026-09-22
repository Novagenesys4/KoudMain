<?php

namespace Tests;

use App\Models\Quartier;
use App\Models\Region;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // GARDE-FOU : RefreshDatabase vide TOUTES les tables. On refuse de démarrer si la base visée
        // n'est pas une base de test. Ce contrôle doit précéder parent::setUp(), qui lance les migrations.
        $base = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if (! str_contains($base, 'test')) {
            $this->fail("Les tests vident la base de données : « $base » ne contient pas « test ». Vérifiez DB_DATABASE dans phpunit.xml.");
        }

        parent::setUp();

        // Les vues appellent @vite : on n'a pas besoin des fichiers compilés pour tester la logique.
        $this->withoutVite();
    }

    /** Un quartier existant (les utilisateurs doivent tous être rattachés à un quartier). */
    protected function creerQuartier(string $nom = 'Riviera 2'): Quartier
    {
        $region = Region::firstOrCreate(['nom' => 'Abidjan']);
        $departement = $region->departements()->firstOrCreate(['nom' => 'Abidjan']);
        $ville = $departement->villes()->firstOrCreate(['nom' => 'Abidjan (Cocody)']);

        return $ville->quartiers()->firstOrCreate(['nom' => $nom]);
    }
}
