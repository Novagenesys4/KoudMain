<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quartiers', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->foreignId('ville_id')->constrained('villes')->restrictOnDelete();

            $table->unique(['nom', 'ville_id']);
            $table->index('ville_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quartiers');
    }
};
