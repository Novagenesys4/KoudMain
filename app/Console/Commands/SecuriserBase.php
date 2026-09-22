<?php

namespace App\Console\Commands;

use App\Support\SecuriteBase;
use Illuminate\Console\Command;

/**
 * Règle 4 : active la Row Level Security sur toutes les tables (voir App\Support\SecuriteBase pour le pourquoi).
 * Lancé à CHAQUE démarrage du conteneur (docker/entrypoint.sh), après les migrations : une table ajoutée demain est protégée
 * au déploiement suivant. Sans danger à rejouer.
 *
 *   php artisan koudmain:securiser-base            (applique)
 *   php artisan koudmain:securiser-base --verifier (ne modifie rien ; échoue si une table n'est pas protégée)
 */
class SecuriserBase extends Command
{
    protected $signature = 'koudmain:securiser-base {--verifier : Contrôle seulement, sans rien modifier}';

    protected $description = 'Active la Row Level Security sur toutes les tables du schéma public';

    public function handle(): int
    {
        if ($this->option('verifier')) {
            $manquantes = SecuriteBase::sansProtection();

            if ($manquantes === []) {
                $this->info('RLS active sur toutes les tables du schéma public.');

                return self::SUCCESS;
            }

            $this->error('RLS absente sur : '.implode(', ', $manquantes));

            return self::FAILURE;
        }

        $r = SecuriteBase::appliquer();

        if (! $r['actif']) {
            $this->info('Base non PostgreSQL : rien à faire.');

            return self::SUCCESS;
        }

        $this->info(count($r['activees']) > 0
            ? 'RLS activée sur '.count($r['activees']).' table(s) : '.implode(', ', $r['activees']).'.'
            : 'RLS déjà active sur toutes les tables.');

        if ($r['roles_revoques'] !== []) {
            $this->info('Accès retirés aux rôles d\'API : '.implode(', ', $r['roles_revoques']).'.');
        }

        if ($r['sans_droit'] !== []) {
            $this->warn('Tables que ce rôle ne possède pas, laissées telles quelles : '.implode(', ', $r['sans_droit']).'.');
        }

        return self::SUCCESS;
    }
}
