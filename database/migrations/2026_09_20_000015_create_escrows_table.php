<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Séquestre (escrow) : à la commande, le montant quitte le wallet du client et reste
 * "bloqué" ici jusqu'à la validation du client, l'annulation ou l'arbitrage d'un litige.
 * Une commande = au plus un séquestre (unique sur commande_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->unique()->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('prestataire_id')->constrained('users')->restrictOnDelete();
            $table->decimal('montant', 12, 2);
            $table->string('statut', 20)->default('bloque'); // bloque | libere | rembourse | litige
            $table->timestamp('bloque_at')->useCurrent();
            $table->timestamp('libere_at')->nullable();
            $table->timestamp('rembourse_at')->nullable();

            $table->index('statut');
            $table->index('client_id');
            $table->index('prestataire_id');
        });

        DB::statement('ALTER TABLE escrows ADD CONSTRAINT escrows_montant_positif CHECK (montant > 0)');
        DB::statement("ALTER TABLE escrows ADD CONSTRAINT escrows_statut_valide CHECK (statut IN ('bloque', 'libere', 'rembourse', 'litige'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('escrows');
    }
};
