<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartes_virtuelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('libelle', 80)->default('Carte KoudMain');
            $table->string('type_carte', 20)->default('visa');
            $table->string('couleur', 40)->default('emerald');
            $table->string('numero_masque', 19);
            $table->string('nom_titulaire', 120);
            $table->string('date_expiration', 5)->default('12/28');
            $table->boolean('est_principale')->default(false);
            $table->boolean('est_gelee')->default(false);
            $table->timestamps();

            $table->index(['wallet_id', 'est_gelee']);
        });

        DB::statement("ALTER TABLE cartes_virtuelles ADD CONSTRAINT cartes_virtuelles_type_valide CHECK (type_carte IN ('visa', 'mastercard'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cartes_virtuelles');
    }
};
