<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Avis clients (ex-colonnes evaluation/commentaire de "Cibler").
 * Un avis par couple (commande, prestation) ; il reste modifiable, et chaque version
 * est archivée dans avis_historiques. Règle métier : on ne note qu'une commande Terminée
 * (vérifiée par le service d'avis, lot 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('prestation_id')->constrained('prestations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // auteur = le client
            $table->unsignedTinyInteger('note');
            $table->text('commentaire')->nullable();
            $table->timestamp('modifie_at')->nullable();
            $table->timestamps();

            $table->unique(['commande_id', 'prestation_id']);
            $table->index(['prestation_id', 'note']); // moyennes du catalogue et du profil
        });

        DB::statement('ALTER TABLE avis ADD CONSTRAINT avis_note_valide CHECK (note BETWEEN 1 AND 5)');

        Schema::create('avis_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('prestation_id')->constrained('prestations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('note');
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['commande_id', 'prestation_id']);
        });

        DB::statement('ALTER TABLE avis_historiques ADD CONSTRAINT avis_historiques_note_valide CHECK (note BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('avis_historiques');
        Schema::dropIfExists('avis');
    }
};
