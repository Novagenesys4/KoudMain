<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Page publique et recherche (plan, étapes 13 et 14).
 *
 *  - slug : URL lisible /prestations/coiffure-tresses-k3x9ab (unique ; suffixe aléatoire posé par le modèle) ;
 *  - est_active : on masque une prestation au lieu de la supprimer quand elle a déjà été commandée ;
 *  - search_vector : recherche plein texte française (les pluriels et conjugaisons sont ignorés ; les accents comptent, à traiter au lot 2 : "coiffures" trouve "Coiffure", "réparer" trouve "réparation"),
 *    colonne GÉNÉRÉE par PostgreSQL : toujours à jour, aucun trigger à maintenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestations', function (Blueprint $table) {
            $table->string('slug', 190)->nullable();
            $table->boolean('est_active')->default(true);

            $table->index('prix');
            $table->index(['est_active', 'service_id']);
        });

        // Rétro-remplissage (base non vide) : titre simplifié + id, donc toujours unique.
        DB::statement(<<<'SQL'
            UPDATE prestations
            SET slug = trim(both '-' from regexp_replace(lower(titre), '[^a-z0-9]+', '-', 'g')) || '-' || id
            WHERE slug IS NULL
        SQL);

        DB::statement('ALTER TABLE prestations ALTER COLUMN slug SET NOT NULL');

        Schema::table('prestations', function (Blueprint $table) {
            $table->unique('slug');
        });

        DB::statement("ALTER TABLE prestations ADD CONSTRAINT prestations_titre_min CHECK (length(trim(titre)) >= 3)");

        DB::statement(<<<'SQL'
            ALTER TABLE prestations
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('french', coalesce(titre, '')), 'A') ||
                setweight(to_tsvector('french', coalesce(description, '')), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX prestations_search_idx ON prestations USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prestations_search_idx');
        DB::statement('ALTER TABLE prestations DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE prestations DROP CONSTRAINT IF EXISTS prestations_titre_min');

        Schema::table('prestations', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['prix']);
            $table->dropIndex(['est_active', 'service_id']);
            $table->dropColumn(['slug', 'est_active']);
        });
    }
};
