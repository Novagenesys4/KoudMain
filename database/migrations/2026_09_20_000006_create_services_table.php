<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->foreignId('categorie_id')->constrained('categories')->restrictOnDelete();

            $table->unique(['nom', 'categorie_id']);
            $table->index('categorie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
