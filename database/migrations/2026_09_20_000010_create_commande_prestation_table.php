<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Table pivot (ex-table "Cibler") : les lignes d'une commande.
 * Le prix unitaire est copié au moment de la commande : si le prestataire change
 * son prix plus tard, les anciennes commandes ne bougent pas.
 * Les avis auront leur propre table (étape 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commande_prestation', function (Blueprint $table) {
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            // restrictOnDelete : une prestation déjà commandée ne peut pas être supprimée
            // (on la désactivera plutôt, à l'étape 5).
            $table->foreignId('prestation_id')->constrained('prestations')->restrictOnDelete();
            $table->decimal('prix_unitaire', 10, 2);
            $table->unsignedInteger('quantite')->default(1);

            $table->primary(['commande_id', 'prestation_id']);
            $table->index('prestation_id');
        });

        DB::statement('ALTER TABLE commande_prestation ADD CONSTRAINT commande_prestation_quantite_positive CHECK (quantite > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_prestation');
    }
};
