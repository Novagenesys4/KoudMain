<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 3 : commandes réelles, argent réel.
 *
 *  - commandes : date souhaitée, durée prévue (pour ne jamais réserver deux fois le même créneau) et précisions du client ;
 *  - paiements : chaque recharge par Mobile Money (une ligne par tentative, jamais créditée deux fois) ;
 *  - retraits  : chaque demande de retrait d'un prestataire (l'argent quitte son solde tout de suite,
 *                puis un administrateur confirme le virement ou le refuse, ce qui rend l'argent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->timestamp('date_souhaitee')->nullable();
            $table->unsignedSmallInteger('duree_minutes')->nullable(); // durée totale prévue (durée x quantité)
            $table->string('precisions', 500)->nullable();
        });

        // Accélère la recherche de créneaux déjà pris (le chevauchement lui-même est vérifié par le service).
        DB::statement(<<<'SQL'
            CREATE INDEX commandes_creneaux_idx ON commandes (prestataire_id, date_souhaitee)
            WHERE statut IN ('acceptee', 'en_cours') AND date_souhaitee IS NOT NULL
        SQL);

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reference', 40)->unique();         // notre identifiant, envoyé à l'agrégateur
            $table->string('fournisseur', 20);                 // simulation | cinetpay | paydunya
            $table->string('reference_fournisseur', 100)->nullable();
            $table->string('methode', 40);                     // Orange Money, MTN MoMo, Wave, Carte bancaire
            $table->decimal('montant', 12, 2);
            $table->string('statut', 20)->default('en_attente'); // en_attente | reussi | echoue | annule
            $table->string('telephone', 20)->nullable();
            $table->text('url_paiement')->nullable();          // page de paiement de l'agrégateur
            $table->string('motif_echec', 200)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('reussi_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['statut', 'created_at']);
        });
        DB::statement('ALTER TABLE paiements ADD CONSTRAINT paiements_montant_positif CHECK (montant > 0)');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_statut_valide CHECK (statut IN ('en_attente', 'reussi', 'echoue', 'annule'))");

        Schema::create('retraits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('montant', 12, 2);
            $table->string('methode', 40);                     // Orange Money, MTN MoMo, Wave, Virement bancaire
            $table->string('destination', 60);                 // numéro de téléphone ou RIB
            $table->string('statut', 20)->default('en_attente'); // en_attente | effectue | refuse
            $table->string('motif_refus', 300)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['statut', 'created_at']);
        });
        DB::statement('ALTER TABLE retraits ADD CONSTRAINT retraits_montant_positif CHECK (montant > 0)');
        DB::statement("ALTER TABLE retraits ADD CONSTRAINT retraits_statut_valide CHECK (statut IN ('en_attente', 'effectue', 'refuse'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('retraits');
        Schema::dropIfExists('paiements');
        DB::statement('DROP INDEX IF EXISTS commandes_creneaux_idx');

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn(['date_souhaitee', 'duree_minutes', 'precisions']);
        });
    }
};
