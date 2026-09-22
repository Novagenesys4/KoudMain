<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 5 : suivi des tâches planifiées et index des requêtes de la page « Métriques ».
 *
 *  - taches_planifiees : une ligne par tâche (libération des paiements, nettoyage, instantané des métriques, battement du
 *    planificateur...) avec la date, le résultat et la durée de sa dernière exécution. La page « Métriques et santé » s'en sert
 *    pour dire si le planificateur tourne vraiment.
 *  - users.created_at et notifications.created_at : « nouveaux comptes par jour » et la purge des vieilles notifications
 *    ne parcourent plus toute la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taches_planifiees', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 60)->unique();
            $table->timestamp('derniere_execution')->nullable();
            $table->string('statut', 10)->default('jamais'); // jamais, ok, erreur
            $table->string('resume', 255)->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->unsignedInteger('executions')->default(0);
            $table->unsignedInteger('echecs')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::dropIfExists('taches_planifiees');
    }
};
