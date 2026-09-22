<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Messagerie (plan, étape 11). Une conversation par commande, créée "à la demande"
 * (au premier message ou à l'ouverture depuis la commande), jamais dans une boucle d'affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->unique()->constrained('commandes')->cascadeOnDelete();
            $table->timestamp('dernier_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('expediteur_id')->constrained('users')->cascadeOnDelete();
            $table->text('contenu');
            $table->boolean('lu')->default(false);
            $table->timestamp('created_at')->useCurrent(); // un message ne se modifie pas

            $table->index(['conversation_id', 'created_at']);
            $table->index(['expediteur_id', 'lu']);
        });

        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_contenu_longueur CHECK (length(trim(contenu)) BETWEEN 1 AND 5000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
