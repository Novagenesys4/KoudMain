<?php

use App\Support\SecuriteBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * API mobile : vérification d'identité des prestataires (écran « Vérifions votre identité » du prototype).
 *
 *  - demandes_kyc : une demande envoyée par un prestataire (en_attente → validee | refusee). La dernière compte.
 *  - demande_kyc_categorie / demande_kyc_ville : les services proposés et les zones d'intervention déclarés.
 *  - documents_kyc : recto, verso et selfie. Le fichier est CHIFFRÉ (APP_KEY) ; la base garde son emplacement,
 *    son type et une empreinte SHA-256 du contenu déchiffré (preuve d'intégrité), jamais l'image elle-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_kyc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('statut', 20)->default('en_attente');
            $table->string('motif_refus', 500)->nullable();
            $table->foreignId('traitee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traitee_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'statut']);
        });

        Schema::create('demande_kyc_categorie', function (Blueprint $table) {
            $table->foreignId('demande_kyc_id')->constrained('demandes_kyc')->cascadeOnDelete();
            $table->foreignId('categorie_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['demande_kyc_id', 'categorie_id']);
        });

        Schema::create('demande_kyc_ville', function (Blueprint $table) {
            $table->foreignId('demande_kyc_id')->constrained('demandes_kyc')->cascadeOnDelete();
            $table->foreignId('ville_id')->constrained('villes')->cascadeOnDelete();
            $table->primary(['demande_kyc_id', 'ville_id']);
        });

        Schema::create('documents_kyc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_kyc_id')->constrained('demandes_kyc')->cascadeOnDelete();
            $table->string('type', 10); // recto | verso | selfie
            $table->string('disk', 20);
            $table->string('chemin');
            $table->string('mime', 30);
            $table->unsignedInteger('taille_octets');
            $table->char('empreinte', 64);
            $table->timestamps();

            $table->unique(['demande_kyc_id', 'type']);
        });

        SecuriteBase::appliquer();
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_kyc');
        Schema::dropIfExists('demande_kyc_ville');
        Schema::dropIfExists('demande_kyc_categorie');
        Schema::dropIfExists('demandes_kyc');
    }
};
