<?php

namespace App\Console\Commands;

use App\Support\ControleProduction;
use Illuminate\Console\Command;

/**
 * Règle 20 (sauvegarde des secrets) : la liste des secrets dont l'application a besoin, et lesquels sont renseignés.
 * AUCUNE valeur n'est affichée : seulement le NOM de la variable et « renseigné » / « vide ». Cette liste sert de check-list
 * pour ranger chaque valeur dans votre gestionnaire de mots de passe (Bitwarden, 1Password...) : si Render disparaît demain,
 * vous pouvez redéployer le site ailleurs avec ces valeurs.
 *
 *   php artisan koudmain:inventaire-secrets
 *
 * Attention : APP_KEY est irremplaçable. La perdre rend illisibles les sessions chiffrées et invalide tous les liens signés
 * (confirmation d'e-mail) ; la changer déconnecte tout le monde. Gardez-en toujours une copie.
 */
class InventaireSecrets extends Command
{
    /** @var array<string, string> variable => à quoi elle sert */
    private const SECRETS = [
        'APP_KEY' => 'Clé de chiffrement de l\'application (sessions, liens signés). IRREMPLAÇABLE : gardez-en une copie.',
        'DB_PASSWORD' => 'Mot de passe de la base Supabase.',
        'SUPABASE_SERVICE_KEY' => 'Clé service_role Supabase (photos). Tous les droits : jamais côté navigateur.',
        'MAIL_PASSWORD' => 'Mot de passe (ou clé API) du service d\'e-mails.',
        'CINETPAY_API_KEY' => 'Clé API CinetPay (paiements Mobile Money).',
        'CINETPAY_SECRET_KEY' => 'Clé secrète CinetPay : signature des notifications de paiement.',
        'SENTRY_DSN' => 'Adresse d\'envoi des erreurs vers Sentry (facultatif).',
    ];

    protected $signature = 'koudmain:inventaire-secrets';

    protected $description = 'Liste les secrets nécessaires et lesquels sont renseignés (sans jamais afficher leur valeur)';

    public function handle(): int
    {
        $env = ControleProduction::environnement();
        $lignes = [];

        foreach (self::SECRETS as $nom => $role) {
            $lignes[] = [$nom, trim((string) ($env[$nom] ?? '')) !== '' ? 'renseigné' : 'VIDE', $role];
        }

        $this->table(['Variable', 'État', 'Rôle'], $lignes);
        $this->line('Aucune valeur n\'est affichée. Rangez chacune dans votre gestionnaire de mots de passe (voir SECURITE.md, « Sauvegarde des secrets »).');

        return self::SUCCESS;
    }
}
