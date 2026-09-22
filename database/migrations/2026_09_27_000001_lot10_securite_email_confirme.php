<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Règle 19 : l'adresse e-mail doit être confirmée pour activer un compte.
 *
 * Les comptes qui existent AVANT cette règle ont été créés sans confirmation : les bloquer d'un coup enfermerait dehors tous les
 * utilisateurs (et l'administrateur). Ils sont donc considérés comme confirmés. Seuls les comptes créés après ce déploiement
 * passent par le lien de confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Rien à défaire : on ne sait plus quels comptes étaient réellement non confirmés.
    }
};
