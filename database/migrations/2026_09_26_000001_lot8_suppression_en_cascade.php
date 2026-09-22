<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'un compte « en cascade » : quand l'administrateur supprime un utilisateur, tout ce qui lui appartient
 * suit (commandes, séquestres, paiements, retraits, wallet, messages, avis…), au lieu d'être bloqué par la base.
 *
 * - commandes / escrows / paiements / retraits : RESTRICT -> CASCADE sur la clé vers users.
 * - commande_prestation.prestation_id : RESTRICT -> NO ACTION DEFERRABLE INITIALLY DEFERRED. RESTRICT (et NO ACTION
 *   immédiat) échoue dès que la cascade « users -> prestations » passe avant la cascade « users -> commandes ». Différée,
 *   la vérification a lieu à la fin de la transaction, quand toutes les cascades sont faites : supprimer un prestataire
 *   efface ses prestations ET les commandes qui les contiennent, dans n'importe quel ordre. Supprimer une seule
 *   prestation déjà commandée reste refusé (à la validation de la transaction ; sans transaction, tout de suite).
 *
 * L'argent en séquestre dû à l'AUTRE partie est rendu par UtilisateurService avant la suppression (voir CommandeService).
 * Le journal applicatif garde une ligne « admin.compte_supprime » avec les montants effacés.
 */
return new class extends Migration
{
    /** [table, colonne, table référencée, règle après (CASCADE | NO ACTION DEFERRABLE INITIALLY DEFERRED), règle avant]. */
    private const CLES = [
        ['commandes', 'client_id', 'users', 'CASCADE', 'RESTRICT'],
        ['commandes', 'prestataire_id', 'users', 'CASCADE', 'RESTRICT'],
        ['escrows', 'client_id', 'users', 'CASCADE', 'RESTRICT'],
        ['escrows', 'prestataire_id', 'users', 'CASCADE', 'RESTRICT'],
        ['paiements', 'user_id', 'users', 'CASCADE', 'RESTRICT'],
        ['retraits', 'user_id', 'users', 'CASCADE', 'RESTRICT'],
        ['commande_prestation', 'prestation_id', 'prestations', 'NO ACTION DEFERRABLE INITIALLY DEFERRED', 'RESTRICT'],
    ];

    public function up(): void
    {
        foreach (self::CLES as [$table, $colonne, $cible, $apres]) {
            $this->remplacer($table, $colonne, $cible, $apres);
        }
    }

    public function down(): void
    {
        foreach (self::CLES as [$table, $colonne, $cible, , $avant]) {
            $this->remplacer($table, $colonne, $cible, $avant);
        }
    }

    private function remplacer(string $table, string $colonne, string $cible, string $regle): void
    {
        $nom = "{$table}_{$colonne}_foreign";

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$nom}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$nom} FOREIGN KEY ({$colonne}) REFERENCES {$cible} (id) ON DELETE {$regle}");
    }
};
