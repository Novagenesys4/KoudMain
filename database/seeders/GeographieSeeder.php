<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Charge régions > départements > villes > quartiers depuis database/data/geographie_ci.json
 * (fichier produit par tools/extraire_geographie.php).
 *
 * Rejouable sans risque : firstOrCreate ne crée jamais de doublon.
 */
class GeographieSeeder extends Seeder
{
    public function run(): void
    {
        $chemin = database_path('data/geographie_ci.json');

        if (!File::exists($chemin)) {
            $this->command->error(
                "Fichier introuvable : $chemin\n"
                . 'Générez-le d\'abord : php tools/extraire_geographie.php <ancien_fichier.sql>'
            );

            return;
        }

        $regions = json_decode(File::get($chemin), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($regions) {
            foreach ($regions as $r) {
                $region = Region::firstOrCreate(['nom' => $r['nom']]);

                foreach ($r['departements'] as $d) {
                    $departement = $region->departements()->firstOrCreate(['nom' => $d['nom']]);

                    foreach ($d['villes'] as $v) {
                        $ville = $departement->villes()->firstOrCreate(['nom' => $v['nom']]);

                        foreach ($v['quartiers'] as $nomQuartier) {
                            $ville->quartiers()->firstOrCreate(['nom' => $nomQuartier]);
                        }
                    }
                }
            }
        });

        $this->command->info('Géographie chargée.');
    }
}
