<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Compteurs applicatifs (connexions, inscriptions, paiements libérés...) affichés
 * dans le tableau de bord administrateur (lot 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metriques', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->decimal('valeur', 14, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['nom', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metriques');
    }
};
