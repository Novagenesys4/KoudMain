<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lot 6.
 *
 * 1. Cartes bancaires SAISIES par l'utilisateur (au lieu d'être fabriquées par l'application) :
 *      - adresse de facturation ;
 *      - « empreinte » : HMAC du numéro avec la clé de l'application. À sens unique, elle sert seulement à refuser deux fois la même
 *        carte. Le numéro complet et le code de sécurité (CVV) ne sont JAMAIS enregistrés ;
 *      - American Express devient un réseau accepté ;
 *      - l'unicité passe des « 4 derniers chiffres » (deux vraies cartes peuvent les partager) à l'empreinte.
 *    Les cartes fabriquées par les anciennes versions (sans empreinte) et JAMAIS utilisées sont supprimées : un nouvel utilisateur
 *    n'a aucune carte tant qu'il n'en a pas ajouté. Celles qui ont déjà servi restent, pour ne pas casser l'historique.
 *
 * 2. Mode de paiement d'une commande : physique (main à la main), Mobile Money ou carte bancaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartes_virtuelles', function (Blueprint $table) {
            $table->string('adresse_facturation', 255)->nullable()->after('date_expiration');
            $table->char('empreinte', 64)->nullable()->after('adresse_facturation');
        });

        DB::statement('ALTER TABLE cartes_virtuelles DROP CONSTRAINT IF EXISTS cartes_virtuelles_type_valide');
        DB::statement("ALTER TABLE cartes_virtuelles ADD CONSTRAINT cartes_virtuelles_type_valide CHECK (type_carte IN ('visa', 'mastercard', 'amex'))");

        DB::statement('DROP INDEX IF EXISTS cartes_virtuelles_numero_unique');
        DB::statement('CREATE UNIQUE INDEX cartes_virtuelles_empreinte_unique ON cartes_virtuelles (wallet_id, empreinte) WHERE empreinte IS NOT NULL');

        // Cartes de démonstration des versions précédentes, jamais utilisées : elles disparaissent.
        DB::statement(<<<'SQL'
            DELETE FROM cartes_virtuelles c
            WHERE c.empreinte IS NULL
              AND NOT EXISTS (SELECT 1 FROM wallet_transactions t WHERE t.carte_id = c.id)
              AND NOT EXISTS (SELECT 1 FROM paiements p WHERE p.carte_id = c.id)
        SQL);

        // Les cartes conservées : s'il n'y a plus de carte par défaut dans un wallet, la plus ancienne le devient.
        DB::statement(<<<'SQL'
            UPDATE cartes_virtuelles c SET est_principale = TRUE
            WHERE c.id = (SELECT MIN(x.id) FROM cartes_virtuelles x WHERE x.wallet_id = c.wallet_id)
              AND NOT EXISTS (SELECT 1 FROM cartes_virtuelles y WHERE y.wallet_id = c.wallet_id AND y.est_principale)
        SQL);

        Schema::table('commandes', function (Blueprint $table) {
            // Les commandes existantes ont toutes été payées depuis le wallet : on les range sous « mobile_money ».
            $table->string('mode_paiement', 20)->default('mobile_money')->after('montant_total');
        });

        DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_mode_paiement_valide CHECK (mode_paiement IN ('physique', 'mobile_money', 'carte'))");
        DB::statement('CREATE INDEX commandes_mode_paiement_index ON commandes (mode_paiement)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commandes_mode_paiement_index');
        DB::statement('ALTER TABLE commandes DROP CONSTRAINT IF EXISTS commandes_mode_paiement_valide');

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('mode_paiement');
        });

        DB::statement('DROP INDEX IF EXISTS cartes_virtuelles_empreinte_unique');
        // Les cartes American Express ne rentrent plus dans l'ancienne contrainte : on les retire avant de la remettre.
        DB::statement("DELETE FROM cartes_virtuelles WHERE type_carte = 'amex'");
        DB::statement('ALTER TABLE cartes_virtuelles DROP CONSTRAINT IF EXISTS cartes_virtuelles_type_valide');
        DB::statement("ALTER TABLE cartes_virtuelles ADD CONSTRAINT cartes_virtuelles_type_valide CHECK (type_carte IN ('visa', 'mastercard'))");

        Schema::table('cartes_virtuelles', function (Blueprint $table) {
            $table->dropColumn(['adresse_facturation', 'empreinte']);
        });
        // L'ancien index unique sur (wallet_id, numero_masque) n'est pas recréé : des doublons ont pu apparaître entre-temps.
    }
};
