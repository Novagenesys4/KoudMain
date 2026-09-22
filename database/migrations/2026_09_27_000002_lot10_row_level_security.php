<?php

use App\Support\SecuriteBase;
use Illuminate\Database\Migrations\Migration;

/**
 * Règle 4 : Row Level Security sur toutes les tables (voir App\Support\SecuriteBase). Le démarrage du conteneur rejoue la même
 * opération (koudmain:securiser-base) pour couvrir les tables ajoutées plus tard ; cette migration protège une base neuve
 * dès le `php artisan migrate`, même si on la migre hors du conteneur.
 */
return new class extends Migration
{
    public function up(): void
    {
        SecuriteBase::appliquer();
    }

    public function down(): void
    {
        // Volontairement vide : on ne désactive jamais une protection.
    }
};
