<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 4 : le temps réel, la messagerie et les préférences de notification.
 *
 *  - evenements_temps_reel : la « boîte aux lettres » de chaque utilisateur. Chaque fois qu'il se passe quelque chose qui le
 *    concerne (nouveau message, commande acceptée, notification...), une ligne y est ajoutée ; le navigateur, connecté en
 *    flux (SSE), la reçoit dans la seconde. Rien de sensible n'y reste : les lignes sont purgées après quelques heures.
 *  - users.notifications_email : l'utilisateur peut refuser les e-mails (les notifications dans le site restent).
 *  - index partiel sur les messages non lus : compter les non lus (pastille du menu) reste instantané.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenements_temps_reel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->jsonb('donnees')->default('{}');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']); // « donne-moi ce qui est arrivé depuis l'événement N »
            $table->index('created_at');      // la purge
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notifications_email')->default(true);
        });

        DB::statement('CREATE INDEX messages_non_lus ON messages (conversation_id, expediteur_id) WHERE lu = false');
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_contenu_longueur');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_contenu_longueur CHECK (length(trim(contenu)) BETWEEN 1 AND 2000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_contenu_longueur');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_contenu_longueur CHECK (length(trim(contenu)) BETWEEN 1 AND 5000)');
        DB::statement('DROP INDEX IF EXISTS messages_non_lus');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_email');
        });

        Schema::dropIfExists('evenements_temps_reel');
    }
};
