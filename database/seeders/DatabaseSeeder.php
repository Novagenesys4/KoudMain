<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de référence uniquement. Volontairement AUCUN compte admin par défaut
     * (l'ancien admin@service.ci / "password" était une faille) : l'admin sera créé
     * par une commande artisan à l'étape 3.
     */
    public function run(): void
    {
        $this->call([
            GeographieSeeder::class,
            CategorieServiceSeeder::class,
        ]);
    }
}
