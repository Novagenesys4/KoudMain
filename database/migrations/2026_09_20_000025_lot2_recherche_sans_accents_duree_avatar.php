<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 2 : catalogue, recherche et photos.
 *
 *  1. Recherche SANS ACCENTS. L'extension PostgreSQL "unaccent" retire les accents ("réparation" -> "reparation").
 *     Sa fonction n'est pas déclarée "immutable", ce qui l'interdit dans une colonne générée ou un index :
 *     on l'enveloppe donc dans immutable_unaccent(), déclarée immutable (recette classique, sans danger tant
 *     que le dictionnaire ne change pas). Le schéma de l'extension est détecté : à la maison c'est "public",
 *     sur Supabase l'extension peut avoir été activée dans le schéma "extensions".
 *  2. search_vector est recréée sur le texte sans accents : "reparer", "réparer" et "réparation" se trouvent
 *     désormais entre eux, et un client qui tape sans accent (le cas courant sur téléphone) trouve tout.
 *  3. duree_minutes : durée estimée d'une prestation (affichée sur les cartes et la page publique).
 *  4. Un seul avatar par utilisateur, garanti par la base (index unique partiel sur la table medias).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        $schema = DB::selectOne(<<<'SQL'
            SELECT n.nspname AS nom
            FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
            WHERE e.extname = 'unaccent'
        SQL)->nom;

        // $schema vient du catalogue de PostgreSQL (pas d'une saisie) ; on le protège quand même par des guillemets.
        $s = '"'.str_replace('"', '""', $schema).'"';

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION immutable_unaccent(texte text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS \$\$ SELECT {$s}.unaccent('{$s}.unaccent'::regdictionary, texte) \$\$
        SQL);

        DB::statement('DROP INDEX IF EXISTS prestations_search_idx');
        DB::statement('ALTER TABLE prestations DROP COLUMN search_vector');
        DB::statement(<<<'SQL'
            ALTER TABLE prestations
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('french', immutable_unaccent(coalesce(titre, ''))), 'A') ||
                setweight(to_tsvector('french', immutable_unaccent(coalesce(description, ''))), 'B')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX prestations_search_idx ON prestations USING GIN (search_vector)');

        Schema::table('prestations', function (Blueprint $table) {
            $table->unsignedSmallInteger('duree_minutes')->nullable();
        });
        DB::statement('ALTER TABLE prestations ADD CONSTRAINT prestations_duree_valide CHECK (duree_minutes IS NULL OR duree_minutes BETWEEN 15 AND 1440)');

        DB::statement("CREATE UNIQUE INDEX medias_un_seul_avatar ON medias (mediable_type, mediable_id) WHERE type = 'avatar'");
        // Les photos d'une prestation sont toujours lues dans l'ordre : type + position.
        DB::statement("CREATE INDEX medias_photos_ordre_idx ON medias (mediable_type, mediable_id, position, id) WHERE type = 'photo_prestation'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS medias_photos_ordre_idx');
        DB::statement('DROP INDEX IF EXISTS medias_un_seul_avatar');

        DB::statement('ALTER TABLE prestations DROP CONSTRAINT IF EXISTS prestations_duree_valide');
        Schema::table('prestations', function (Blueprint $table) {
            $table->dropColumn('duree_minutes');
        });

        // Retour à la recherche d'avant (avec accents).
        DB::statement('DROP INDEX IF EXISTS prestations_search_idx');
        DB::statement('ALTER TABLE prestations DROP COLUMN search_vector');
        DB::statement(<<<'SQL'
            ALTER TABLE prestations
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('french', coalesce(titre, '')), 'A') ||
                setweight(to_tsvector('french', coalesce(description, '')), 'B')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX prestations_search_idx ON prestations USING GIN (search_vector)');

        DB::statement('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
        // L'extension unaccent est laissée en place : d'autres objets peuvent en dépendre.
    }
};
