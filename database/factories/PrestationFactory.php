<?php

namespace Database\Factories;

use App\Models\Categorie;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Prestation>
 */
class PrestationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'prestataire_id' => User::factory()->prestataire(),
            'service_id' => fn () => Service::query()->inRandomOrder()->value('id') ?? self::unService()->id,
            'titre' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(12),
            'prix' => fake()->numberBetween(20, 400) * 500,
            'duree_minutes' => null,
            'est_active' => true,
        ];
    }

    /** Masquée : absente du catalogue. */
    public function masquee(): static
    {
        return $this->state(['est_active' => false]);
    }

    /** Un service existe toujours (les tests n'exécutent pas les seeders). */
    private static function unService(): Service
    {
        return Categorie::firstOrCreate(['nom' => 'Beauté et Coiffure'])->services()->firstOrCreate(['nom' => 'Coiffure femme']);
    }
}
