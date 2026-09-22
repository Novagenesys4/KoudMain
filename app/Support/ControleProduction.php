<?php

namespace App\Support;

/**
 * Contrôle de configuration « prêt pour la production » : chaque règle de sécurité qui dépend d'une VARIABLE D'ENVIRONNEMENT
 * (et donc d'un oubli possible dans le tableau de bord de Render) est vérifiée ici, une seule fois, au démarrage du conteneur
 * (docker/entrypoint.sh) et sur la page admin « Métriques et santé ».
 *
 *   niveau « erreur »    : le service ne doit PAS démarrer ainsi (secret par défaut, débogage ouvert, paiement simulé...) ;
 *   niveau « attention » : ça fonctionne, mais un réglage recommandé manque.
 *
 * Aucune valeur secrète n'est jamais renvoyée ni écrite : seuls les NOMS des variables apparaissent dans les messages.
 */
class ControleProduction
{
    /** Mots de passe de développement connus : s'ils arrivent en production, c'est une erreur de configuration. */
    private const MOTS_DE_PASSE_DE_DEV = ['koudmain_dev', 'password', 'secret', 'root', 'postgres', 'admin', '123456'];

    /**
     * @param  array<string, string>|null  $env  Variables d'environnement à examiner (par défaut : celles du processus).
     * @return list<array{niveau: string, regle: int, message: string}>
     */
    public static function verifier(?array $env = null, ?string $racine = null): array
    {
        $env ??= self::environnement();
        $racine ??= base_path();
        $p = [];

        $ajouter = function (string $niveau, int $regle, string $message) use (&$p): void {
            $p[] = ['niveau' => $niveau, 'regle' => $regle, 'message' => $message];
        };

        // Règles 1 et 2 : les secrets vivent dans les variables d'environnement de la plateforme, jamais dans un fichier.
        if (is_file($racine.'/.env')) {
            $ajouter('erreur', 1, 'Un fichier .env existe dans le conteneur : en production, les secrets viennent uniquement des variables d\'environnement de Render (Environment Group ou Environment). Supprimez ce fichier.');
        }

        if (trim((string) ($env['APP_KEY'] ?? '')) === '') {
            $ajouter('erreur', 1, 'APP_KEY est vide : les sessions et les liens signés ne seraient pas protégés.');
        }

        $motDePasseBase = (string) ($env['DB_PASSWORD'] ?? '');
        if ($motDePasseBase === '' && trim((string) ($env['DB_URL'] ?? '')) === '') {
            $ajouter('erreur', 1, 'DB_PASSWORD est vide.');
        } elseif (in_array(mb_strtolower($motDePasseBase), self::MOTS_DE_PASSE_DE_DEV, true)) {
            $ajouter('erreur', 1, 'DB_PASSWORD est un mot de passe de développement connu : changez-le (Supabase > Settings > Database > Reset database password).');
        }

        if (! in_array(mb_strtolower((string) ($env['DB_SSLMODE'] ?? 'prefer')), ['require', 'verify-ca', 'verify-full'], true)) {
            $ajouter('attention', 1, 'DB_SSLMODE n\'impose pas TLS (mettez « require ») : le mot de passe et les données de la base circuleraient sans chiffrement garanti.');
        }

        // Règle 14 : jamais d'erreur détaillée en production.
        if (filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)) {
            $ajouter('erreur', 14, 'APP_DEBUG=true : les erreurs afficheraient le code, les chemins et les variables. Mettez APP_DEBUG=false.');
        }

        if (in_array(mb_strtolower((string) ($env['LOG_LEVEL'] ?? 'info')), ['debug'], true)) {
            $ajouter('attention', 15, 'LOG_LEVEL=debug : les journaux de production deviennent bavards (et plus susceptibles de contenir des données personnelles). Utilisez « info ».');
        }

        // Règle 8 : HTTPS partout.
        $url = (string) ($env['APP_URL'] ?? '');
        $hote = (string) parse_url($url, PHP_URL_HOST);
        if (! str_starts_with(mb_strtolower($url), 'https://') || in_array($hote, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($hote, '.test') || str_ends_with($hote, '.local')) {
            $ajouter('erreur', 8, 'APP_URL doit être l\'adresse publique du site en https:// (les liens d\'e-mail et de paiement en dépendent).');
        }

        if (! filter_var($env['TRUST_PROXY'] ?? false, FILTER_VALIDATE_BOOL)) {
            $ajouter('erreur', 8, 'TRUST_PROXY n\'est pas à true : derrière le proxy de Render, le site ne saurait pas qu\'il est en HTTPS (redirection, cookies sécurisés, adresse IP réelle des visiteurs).');
        }

        if (($env['SESSION_SECURE_COOKIE'] ?? '') !== '' && ! filter_var($env['SESSION_SECURE_COOKIE'], FILTER_VALIDATE_BOOL)) {
            $ajouter('erreur', 8, 'SESSION_SECURE_COOKIE=false : le cookie de session pourrait circuler en clair.');
        }

        if (isset($env['FORCE_HTTPS']) && ! filter_var($env['FORCE_HTTPS'], FILTER_VALIDATE_BOOL)) {
            $ajouter('attention', 8, 'FORCE_HTTPS=false : le site ne redirige plus http:// vers https://.');
        }

        // Règle 9 : les sessions expirent.
        if ((int) ($env['SESSION_LIFETIME'] ?? 120) > 120) {
            $ajouter('attention', 9, 'SESSION_LIFETIME dépasse 120 minutes d\'inactivité.');
        }

        if (($env['SESSION_ENCRYPT'] ?? '') !== '' && ! filter_var($env['SESSION_ENCRYPT'], FILTER_VALIDATE_BOOL)) {
            $ajouter('attention', 9, 'SESSION_ENCRYPT=false : le contenu des sessions n\'est pas chiffré sur le disque.');
        }

        // Règle 5 : mots de passe hachés avec un algorithme moderne et un coût suffisant.
        if ((int) ($env['BCRYPT_ROUNDS'] ?? 12) < 12) {
            $ajouter('erreur', 5, 'BCRYPT_ROUNDS est inférieur à 12 : le hachage des mots de passe serait trop rapide à attaquer.');
        }

        if (! in_array(mb_strtolower((string) ($env['HASH_DRIVER'] ?? 'bcrypt')), ['', 'bcrypt', 'argon', 'argon2id'], true)) {
            $ajouter('erreur', 5, 'HASH_DRIVER n\'est ni bcrypt ni argon : les mots de passe doivent être hachés avec bcrypt ou Argon2.');
        }

        // Règle 17 : webhooks signés.
        $pilote = mb_strtolower((string) ($env['PAIEMENT_DRIVER'] ?? 'aucun'));
        if ($pilote === 'simulation') {
            $ajouter('erreur', 17, 'PAIEMENT_DRIVER=simulation : le paiement est fictif (n\'importe qui se créditerait de l\'argent). Utilisez « cinetpay » ou « aucun ».');
        }

        if ($pilote === 'cinetpay') {
            foreach (['CINETPAY_API_KEY', 'CINETPAY_SITE_ID', 'CINETPAY_SECRET_KEY'] as $variable) {
                if (trim((string) ($env[$variable] ?? '')) === '') {
                    $ajouter('erreur', 17, "$variable est vide : sans lui, CinetPay ne peut pas être utilisé ni ses notifications vérifiées.");
                }
            }
        }

        // Règle 19 : la confirmation de l'adresse exige de VRAIS e-mails.
        if (in_array(mb_strtolower((string) ($env['MAIL_MAILER'] ?? 'log')), ['log', 'array', ''], true)) {
            $ajouter('erreur', 19, 'MAIL_MAILER=log : aucun e-mail ne part, donc personne ne peut confirmer son adresse. Configurez un SMTP (Brevo, Resend...).');
        }

        // Règle 7 : seules des clés PUBLIQUES peuvent aller dans le navigateur (toute variable VITE_* est copiée dans le JavaScript).
        foreach (array_keys($env) as $nom) {
            if (str_starts_with($nom, 'VITE_') && preg_match('/(SECRET|PRIVATE|PASSWORD|TOKEN|SERVICE|API_?KEY|SIGNING|WEBHOOK)/i', $nom) === 1 && preg_match('/(PUBLIC|PUBLISHABLE|ANON)/i', $nom) !== 1) {
                $ajouter('erreur', 7, "$nom sera copiée dans le JavaScript public : une clé privée ne doit jamais commencer par VITE_. Renommez-la (sans VITE_) : elle restera côté serveur.");
            }
        }

        // Règle 13 : CORS, liste blanche stricte.
        if (isset($env['CORS_ALLOWED_ORIGINS']) && str_contains((string) $env['CORS_ALLOWED_ORIGINS'], '*')) {
            $ajouter('attention', 13, 'CORS_ALLOWED_ORIGINS contient « * » : ce joker est ignoré par KoudMain, listez les origines exactes (https://...).');
        }

        if (filter_var($env['KOUDMAIN_VITRINE'] ?? false, FILTER_VALIDATE_BOOL)) {
            $ajouter('attention', 14, 'KOUDMAIN_VITRINE=true : la page de démonstration /composants est publique. À retirer en production.');
        }

        return $p;
    }

    /** Les variables d'environnement du processus (getenv, $_ENV et $_SERVER fusionnés : cela dépend de la configuration de PHP). @return array<string, string> */
    public static function environnement(): array
    {
        $tout = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ((array) $source as $cle => $valeur) {
                if (is_string($cle) && is_string($valeur)) {
                    $tout[$cle] = $valeur;
                }
            }
        }

        return $tout;
    }
}
