<?php

namespace Database\Seeders;

use App\Models\Categorie;
use Illuminate\Database\Seeder;

class CategorieServiceSeeder extends Seeder
{
    public function run(): void
    {
        // categorie => services (les 4 dernières catégories n'ont pas encore de service, comme avant)
        $catalogue = [
            'Beauté et Coiffure' => ['Coiffure femme', 'Coiffure homme', 'Manucure'],
            'Plomberie et Sanitaire' => ['Réparation fuite', 'Installation sanitaire'],
            'Laverie et Pressing' => ['Lavage vêtements', 'Repassage'],
            "Garde d'enfants" => ['Garde à domicile', 'Baby-sitting'],
            'Cuisine et Traiteur' => ['Cuisine à domicile', 'Traiteur événement'],
            'Électricité' => [],
            'Jardinage' => [],
            'Déménagement' => [],
            'Informatique' => ['Dépannage informatique', 'Réparation smartphone'],
            'Mécanique' => [],
        ];

        foreach ($catalogue as $nomCategorie => $services) {
            $categorie = Categorie::firstOrCreate(['nom' => $nomCategorie]);

            foreach ($services as $nomService) {
                $categorie->services()->firstOrCreate(['nom' => $nomService]);
            }
        }
    }
}
