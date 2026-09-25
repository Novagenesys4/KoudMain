<?php

use App\Support\SecuriteBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * API mobile : vérification du numéro de téléphone par code SMS (OTP).
 *
 *  - users.telephone_verifie_at : date à laquelle le numéro a été confirmé (vide = jamais) ;
 *  - verifications_otp : une demande de code. Le code lui-même n'est JAMAIS stocké en clair (empreinte bcrypt) ; l'identifiant
 *    public est un UUID aléatoire (on ne peut pas deviner celui d'un autre). « objet » dit à quoi sert le code :
 *    inscription, telephone (vérifier le numéro d'un compte existant) ou mot_de_passe (mot de passe oublié).
 *    user_id est vide pour une demande « à blanc » (inscription avec une adresse déjà utilisée, numéro inconnu) : la réponse
 *    est la même que pour une vraie demande (règle 16), mais aucun code n'est envoyé et aucun ne peut être validé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('telephone_verifie_at')->nullable();
        });

        Schema::create('verifications_otp', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('objet', 20);
            $table->string('telephone', 15);
            $table->string('code_hash')->nullable();
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->unsignedSmallInteger('envois')->default(0);
            $table->timestamp('envoye_at')->nullable();
            $table->timestamp('expire_at');
            $table->timestamp('utilise_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'objet']);
            $table->index('expire_at');
        });

        SecuriteBase::appliquer();
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_otp');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telephone_verifie_at');
        });
    }
};
