<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Disponibilités hebdomadaires d'un prestataire (plan, étape 12).
 * jour : 1 = lundi ... 7 = dimanche (norme ISO-8601, celle de Carbon::dayOfWeekIso).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('jour');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->timestamps();

            $table->index(['user_id', 'jour']);
        });

        DB::statement('ALTER TABLE disponibilites ADD CONSTRAINT disponibilites_jour_valide CHECK (jour BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE disponibilites ADD CONSTRAINT disponibilites_plage_valide CHECK (heure_fin > heure_debut)');
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilites');
    }
};
