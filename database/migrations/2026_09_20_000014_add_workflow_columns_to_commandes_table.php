<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Workflow de commande enrichi (plan, étape 9).
 *
 * En attente -> Acceptée -> En cours -> Terminée   (+ Annulée, Litige)
 *
 * Chaque transition laisse sa date : on sait toujours QUAND la commande a changé d'état.
 * Le prestataire est dénormalisé sur la commande (comme l'ancien "id_prestataire") pour
 * filtrer ses commandes sans jointure, et il est toujours déduit côté serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('prestataire_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acceptee_at')->nullable();
            $table->timestamp('debut_at')->nullable();
            $table->timestamp('terminee_at')->nullable();
            $table->timestamp('annulee_at')->nullable();
            $table->timestamp('validee_client_at')->nullable(); // réception confirmée par le client
            $table->text('motif_annulation')->nullable();
            $table->text('motif_litige')->nullable();

            $table->index(['client_id', 'statut']);
            $table->index(['prestataire_id', 'statut']);
        });

        // Base non vide : on retrouve le prestataire à partir des lignes de commande.
        DB::statement(<<<'SQL'
            UPDATE commandes c
            SET prestataire_id = p.prestataire_id
            FROM commande_prestation cp
            JOIN prestations p ON p.id = cp.prestation_id
            WHERE cp.commande_id = c.id
              AND c.prestataire_id IS NULL
        SQL);

        DB::statement('ALTER TABLE commandes ALTER COLUMN prestataire_id SET NOT NULL');

        // Le vocabulaire des statuts est verrouillé au niveau de la base (App\Enums\StatutCommande).
        DB::statement(<<<'SQL'
            ALTER TABLE commandes ADD CONSTRAINT commandes_statut_valide
            CHECK (statut IN ('en_attente', 'acceptee', 'en_cours', 'terminee', 'annulee', 'litige'))
        SQL);

        // Accélère la tâche planifiée qui libère les paiements jamais confirmés par le client.
        DB::statement(<<<'SQL'
            CREATE INDEX commandes_a_liberer_idx ON commandes (terminee_at)
            WHERE statut = 'terminee' AND validee_client_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commandes_a_liberer_idx');
        DB::statement('ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_statut_valide');

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'statut']);
            $table->dropIndex(['prestataire_id', 'statut']);
            $table->dropConstrainedForeignId('prestataire_id');
            $table->dropColumn([
                'acceptee_at',
                'debut_at',
                'terminee_at',
                'annulee_at',
                'validee_client_at',
                'motif_annulation',
                'motif_litige',
            ]);
        });
    }
};
