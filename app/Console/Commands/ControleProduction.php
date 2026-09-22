<?php

namespace App\Console\Commands;

use App\Support\ControleProduction as Controle;
use Illuminate\Console\Command;

/**
 * Refuse de démarrer une production mal configurée (règles 1, 2, 5, 7, 8, 14, 15, 17, 19). Appelé par docker/entrypoint.sh
 * AVANT le démarrage d'Apache : un secret par défaut ou un débogage ouvert arrêtent le déploiement avec un message clair, au lieu
 * de tourner en silence.
 *
 *   php artisan koudmain:controle-production            (échoue s'il y a une « erreur »)
 *   php artisan koudmain:controle-production --avertir  (affiche tout, n'échoue jamais : pour un premier déploiement)
 *   php artisan koudmain:controle-production --force    (contrôle aussi hors production, pour essayer en local)
 *
 * Aucune valeur secrète n'est affichée : seulement le NOM de la variable en cause.
 */
class ControleProduction extends Command
{
    protected $signature = 'koudmain:controle-production {--avertir : Affiche les problèmes sans faire échouer} {--force : Contrôle aussi hors production}';

    protected $description = 'Vérifie que la configuration est sûre pour la production (secrets, débogage, HTTPS, paiement, e-mails)';

    public function handle(): int
    {
        if (! $this->option('force') && ! app()->isProduction()) {
            $this->info('Environnement « '.app()->environment().' » : contrôle de production sans objet (--force pour le lancer quand même).');

            return self::SUCCESS;
        }

        $problemes = Controle::verifier();
        $erreurs = 0;

        foreach ($problemes as $p) {
            $etiquette = $p['niveau'] === 'erreur' ? 'ERREUR   ' : 'ATTENTION';
            $ligne = "[$etiquette] (règle {$p['regle']}) {$p['message']}";
            $p['niveau'] === 'erreur' ? $this->error($ligne) : $this->warn($ligne);
            $erreurs += $p['niveau'] === 'erreur' ? 1 : 0;
        }

        if ($problemes === []) {
            $this->info('Configuration de production conforme.');

            return self::SUCCESS;
        }

        if ($erreurs > 0 && ! $this->option('avertir')) {
            $this->error("$erreurs erreur(s) de configuration : démarrage refusé. Corrigez les variables d'environnement de Render (voir SECURITE.md).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
