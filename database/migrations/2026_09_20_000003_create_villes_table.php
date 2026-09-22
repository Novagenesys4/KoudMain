<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villes', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->foreignId('departement_id')->constrained('departements')->restrictOnDelete();

            $table->unique(['nom', 'departement_id']);
            $table->index('departement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villes');
    }
};
