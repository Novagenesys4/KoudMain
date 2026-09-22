<?php

namespace Database\Factories;

use App\Models\Quartier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Nécessite qu'un quartier existe (php artisan db:seed, ou TestCase::creerQuartier() dans les tests) :
     * chaque utilisateur est rattaché à un quartier existant.
     * Par défaut : un client actif, mot de passe « password ».
     */
    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'telephone' => '07'.fake()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'quartier_id' => fn () => Quartier::query()->inRandomOrder()->value('id'),
            'est_client' => true,
            'est_prestataire' => false,
            'est_admin' => false,
            'est_valide' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Prestataire validé (comme dans l'application : un prestataire n'est pas un client). */
    public function prestataire(): static
    {
        return $this->state(fn (array $attributes) => [
            'est_client' => false,
            'est_prestataire' => true,
            'est_valide' => true,
        ]);
    }

    /** Prestataire inscrit mais pas encore validé par un administrateur. */
    public function enAttente(): static
    {
        return $this->state(fn (array $attributes) => [
            'est_client' => false,
            'est_prestataire' => true,
            'est_valide' => false,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'est_client' => false,
            'est_prestataire' => false,
            'est_admin' => true,
            'est_valide' => true,
        ]);
    }
}
