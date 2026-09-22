<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le journal des actions importantes (paiement, validation d'un prestataire, retrait, litige, connexion...).
 *
 * Une ligne = un événement nommé (« paiement.libere ») + un contexte fait de valeurs simples : identifiants, montants,
 * statuts. En production le journal sort en JSON sur la sortie d'erreur (Render le garde et permet de chercher dedans) ;
 * chaque ligne porte aussi l'identifiant de la requête et de la personne connectée (voir ContexteRequete).
 *
 * Règle : on n'y écrit JAMAIS un secret. Les champs sensibles (mot de passe, jeton, numéro de téléphone ou de compte
 * de retrait, carte...) sont masqués ici même, quoi qu'on passe ; une adresse e-mail est réduite à « m***@domaine.ci ».
 * Un journal qui plante ne doit jamais faire échouer l'action de l'utilisateur.
 */
final class Journal
{
    /** Champs dont la valeur n'est jamais écrite. */
    private const SECRETS = ['password', 'mot_de_passe', 'token', 'jeton', 'secret', 'cle', 'api_key', 'authorization', 'cookie', 'numero_carte', 'cvv', 'pin'];

    /** Champs dont on ne garde que la fin (un numéro reste reconnaissable sans être exploitable). */
    private const PARTIELS = ['telephone', 'destination', 'numero', 'iban'];

    public static function info(string $evenement, array $contexte = []): void
    {
        self::ecrire('info', $evenement, $contexte);
    }

    public static function alerte(string $evenement, array $contexte = []): void
    {
        self::ecrire('warning', $evenement, $contexte);
    }

    /** Une erreur : elle part aussi vers Sentry (si configuré) pour prévenir quelqu'un. */
    public static function erreur(string $evenement, array $contexte = [], ?Throwable $cause = null): void
    {
        if ($cause !== null) {
            $contexte['exception'] = $cause::class;
            $contexte['message'] = mb_substr($cause->getMessage(), 0, 300);
            $contexte['fichier'] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $cause->getFile()).':'.$cause->getLine();
        }

        self::ecrire('error', $evenement, $contexte);

        try {
            $sentry = app(\App\Services\Surveillance\Sentry::class);

            $cause !== null ? $sentry->capturer($cause) : $sentry->message($evenement, 'error', self::nettoyer($contexte));
        } catch (Throwable) {
            // le suivi des erreurs est une commodité
        }
    }

    /** Masque les valeurs sensibles (récursif). Public pour être testé et réutilisé par Sentry. */
    public static function nettoyer(array $contexte): array
    {
        $propre = [];

        foreach ($contexte as $cle => $valeur) {
            $nom = mb_strtolower((string) $cle);

            if (in_array($nom, self::SECRETS, true)) {
                $propre[$cle] = '[masqué]';
            } elseif (is_array($valeur)) {
                $propre[$cle] = self::nettoyer($valeur);
            } elseif (is_string($valeur) && $nom === 'email') {
                $propre[$cle] = self::masquerEmail($valeur);
            } elseif (is_string($valeur) && in_array($nom, self::PARTIELS, true)) {
                $propre[$cle] = self::masquerFin($valeur);
            } elseif (is_string($valeur)) {
                $propre[$cle] = mb_substr($valeur, 0, 500);
            } elseif (is_scalar($valeur) || $valeur === null) {
                $propre[$cle] = $valeur;
            } else {
                $propre[$cle] = '['.get_debug_type($valeur).']';
            }
        }

        return $propre;
    }

    public static function masquerEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '[masqué]';
        }

        [$local, $domaine] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domaine;
    }

    private static function masquerFin(string $valeur): string
    {
        $chiffres = preg_replace('/\s+/', '', $valeur) ?? '';

        return mb_strlen($chiffres) <= 4 ? '****' : '***'.mb_substr($chiffres, -4);
    }

    private static function ecrire(string $niveau, string $evenement, array $contexte): void
    {
        try {
            Log::log($niveau, $evenement, self::nettoyer($contexte));
        } catch (Throwable) {
            // un journal indisponible ne doit jamais casser une page
        }
    }
}
