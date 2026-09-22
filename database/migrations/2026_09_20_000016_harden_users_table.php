<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Durcissement de la table users.
 *  - bio : courte présentation affichée sur le profil public du prestataire ;
 *  - unicité de l'e-mail SANS tenir compte de la casse (Koud@x.ci = koud@x.ci) ;
 *  - format d'e-mail vérifié par la base, en plus de la validation Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bio', 600)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_email_format
            CHECK (email ~* '^[^@\s]+@[^@\s]+\.[^@\s]+$')
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_format');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bio');
        });
    }
};
