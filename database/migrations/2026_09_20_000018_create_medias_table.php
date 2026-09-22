<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Images (plan, étape 7). Une seule table pour toutes les photos : elle pointe vers
 * un modèle quelconque (relation "polymorphe") : une Prestation, un User (photo de profil)...
 *
 * On ne stocke PAS l'image dans la base (l'ancien bytea) : le fichier vit sur un "disque"
 * Laravel (local en développement, stockage S3 de Supabase en production, car le disque
 * de Render est effacé à chaque déploiement). On garde seulement disque + chemin ici.
 *
 * Cette migration remplace aussi l'ancienne colonne prestations.photo_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medias', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable'); // mediable_type + mediable_id (+ index)
            $table->string('type', 30); // photo_prestation | avatar
            $table->string('disk', 30);
            $table->string('chemin');
            $table->string('mime', 20);
            $table->unsignedInteger('taille_octets');
            $table->unsignedSmallInteger('largeur')->nullable();
            $table->unsignedSmallInteger('hauteur')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE medias ADD CONSTRAINT medias_type_valide CHECK (type IN ('photo_prestation', 'avatar'))");
        DB::statement("ALTER TABLE medias ADD CONSTRAINT medias_mime_valide CHECK (mime IN ('image/jpeg', 'image/png', 'image/webp'))");
        // 3 Mo maximum, comme avant.
        DB::statement('ALTER TABLE medias ADD CONSTRAINT medias_taille_max CHECK (taille_octets > 0 AND taille_octets <= 3145728)');

        Schema::table('prestations', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('prestations', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });

        Schema::dropIfExists('medias');
    }
};
