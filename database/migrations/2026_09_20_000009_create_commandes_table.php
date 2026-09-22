<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete : on ne supprime jamais un client qui a un historique de commandes.
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('quartier_id')->constrained('quartiers')->restrictOnDelete();
            $table->decimal('montant_total', 10, 2);
            // Valeurs de App\Enums\StatutCommande. Les colonnes d'escrow arriveront à l'étape 6.
            $table->string('statut', 30)->default('en_attente');
            $table->timestamps();

            $table->index('client_id');
            $table->index('statut');
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE commandes ADD CONSTRAINT commandes_montant_positif CHECK (montant_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};
