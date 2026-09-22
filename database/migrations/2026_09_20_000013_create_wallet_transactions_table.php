<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Registre des mouvements d'argent : on n'y modifie jamais une ligne, on ajoute.
 * C'est pourquoi il n'y a qu'un created_at (pas de updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('type', 20); // credit | debit | retrait
            $table->decimal('montant', 12, 2);
            $table->string('libelle', 200);
            $table->decimal('solde_apres', 12, 2);
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->foreignId('carte_id')->nullable()->constrained('cartes_virtuelles')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index('commande_id');
            $table->index('carte_id');
        });

        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_valide CHECK (type IN ('credit', 'debit', 'retrait'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
