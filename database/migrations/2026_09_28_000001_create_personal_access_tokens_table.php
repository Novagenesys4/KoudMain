<?php

use App\Support\SecuriteBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * API mobile : les jetons de connexion Sanctum (un par appareil connecté).
 * Seule l'empreinte SHA-256 du jeton est stockée : une fuite de la table ne donne accès à aucun compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // Règle 4 : la nouvelle table est protégée par la Row Level Security dès sa création.
        SecuriteBase::appliquer();
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
