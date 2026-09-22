<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('solde', 12, 2)->default(0);
            $table->timestamps(); // updated_at remplace l'ancien trigger "date_maj"
        });

        // Le solde ne peut jamais devenir négatif, quoi qu'il arrive dans le code.
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_solde_positif CHECK (solde >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
