<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Cartes virtuelles du wallet.
 *
 *  - une seule carte principale par wallet (garantie par la base, pas seulement par le code) ;
 *  - deux cartes d'un même wallet ne portent jamais les mêmes 4 derniers chiffres ;
 *  - une recharge en attente se souvient de la carte choisie (la confirmation arrive plus tard, parfois par un autre chemin).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Données de la première version : on garde la plus ancienne carte principale de chaque wallet.
        DB::statement(<<<'SQL'
            UPDATE cartes_virtuelles c SET est_principale = FALSE
            WHERE c.est_principale
              AND EXISTS (SELECT 1 FROM cartes_virtuelles p WHERE p.wallet_id = c.wallet_id AND p.est_principale AND p.id < c.id)
        SQL);

        DB::statement('CREATE UNIQUE INDEX cartes_virtuelles_une_principale ON cartes_virtuelles (wallet_id) WHERE est_principale');
        DB::statement('CREATE UNIQUE INDEX cartes_virtuelles_numero_unique ON cartes_virtuelles (wallet_id, numero_masque)');

        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('carte_id')->nullable()->constrained('cartes_virtuelles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carte_id');
        });

        DB::statement('DROP INDEX IF EXISTS cartes_virtuelles_numero_unique');
        DB::statement('DROP INDEX IF EXISTS cartes_virtuelles_une_principale');
    }
};
