<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * On garde la table "users" fournie par Laravel (l'authentification s'appuie dessus)
 * et on l'adapte à KoudMain. À exécuter sur une table VIDE (base de développement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'nom');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('prenom', 100);
            $table->string('telephone', 15);
            $table->foreignId('quartier_id')->constrained('quartiers')->restrictOnDelete();

            $table->boolean('est_client')->default(true);
            $table->boolean('est_prestataire')->default(false);
            $table->boolean('est_admin')->default(false);
            $table->boolean('est_valide')->default(false);

            $table->index('quartier_id');
            $table->index(['est_prestataire', 'est_valide']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['est_prestataire', 'est_valide']);
            $table->dropConstrainedForeignId('quartier_id');
            $table->dropColumn([
                'prenom',
                'telephone',
                'est_client',
                'est_prestataire',
                'est_admin',
                'est_valide',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('nom', 'name');
        });
    }
};
