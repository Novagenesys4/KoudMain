<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestataire_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->string('titre', 150);
            $table->text('description')->nullable();
            $table->decimal('prix', 10, 2);
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->index('prestataire_id');
            $table->index('service_id');
        });

        // Contrainte de cohérence (ex-phase 2.4 du plan) : un prix est toujours positif.
        DB::statement('ALTER TABLE prestations ADD CONSTRAINT prestations_prix_positif CHECK (prix > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prestations');
    }
};
