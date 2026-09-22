<?php

namespace App\Console\Commands;

use App\Models\Categorie;
use App\Models\Region;
use Database\Seeders\CategorieServiceSeeder;
use Database\Seeders\GeographieSeeder;
use Illuminate\Console\Command;

/**
 * Charge les données de référence (régions > quartiers, catégories > services) SEULEMENT si leur table est vide.
 * Lancée à chaque démarrage du conteneur (docker/entrypoint.sh) : une base neuve devient utilisable toute seule (sans quartiers,
 * personne ne peut s'inscrire), et une base déjà en service n'est jamais touchée — ni les catégories que l'administrateur a
 * renommées ou supprimées, ni le temps de démarrage (deux petites requêtes de comptage).
 */
class Initialiser extends Command
{
    protected $signature = 'koudmain:initialiser';

    protected $description = 'Charge les régions, quartiers, catégories et services si la base n\'en a pas encore.';

    public function handle(): int
    {
        if (Region::query()->doesntExist()) {
            $this->components->task('Régions, villes et quartiers', fn () => $this->call('db:seed', ['--class' => GeographieSeeder::class, '--force' => true]) === 0);
        } else {
            $this->line('Régions et quartiers : déjà en place.');
        }

        if (Categorie::query()->doesntExist()) {
            $this->components->task('Catégories et services', fn () => $this->call('db:seed', ['--class' => CategorieServiceSeeder::class, '--force' => true]) === 0);
        } else {
            $this->line('Catégories et services : déjà en place.');
        }

        return self::SUCCESS;
    }
}
