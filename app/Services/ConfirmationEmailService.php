<?php

namespace App\Services;

use App\Models\User;
use App\Services\Metriques\Enregistreur;
use App\Services\Notifications\Ecouteur;
use App\Services\Notifications\NotificationService;
use App\Support\Journal;
use Illuminate\Support\Facades\URL;

/**
 * Confirmation de l'adresse e-mail (règle 19). Un compte créé sur le SITE n'est activé qu'après un clic sur ce lien. Un compte
 * créé sur l'APPLICATION est déjà actif par son numéro (code SMS) : confirmer l'adresse le « certifie » (badge).
 *
 * Le lien :
 *  - est signé (HMAC avec APP_KEY) : le modifier, ou en fabriquer un, est impossible ;
 *  - expire (config koudmain.securite.confirmation_heures) ;
 *  - contient l'empreinte de l'adresse : s'il servait après un changement d'adresse, il serait refusé ;
 *  - est construit à partir d'APP_URL, jamais de l'en-tête Host de la requête (que le visiteur peut falsifier : c'est ainsi
 *    qu'on envoie à une victime un « lien de confirmation » qui pointe vers un faux site) ;
 *  - ne CONNECTE personne : il confirme l'adresse, puis renvoie vers la page de connexion.
 */
class ConfirmationEmailService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Ecouteur $ecouteur,
    ) {
    }

    /** L'adresse (absolue) du lien de confirmation de ce compte. */
    public function lien(User $utilisateur): string
    {
        $relatif = URL::temporarySignedRoute(
            'email.confirmer',
            now()->addHours((int) config('koudmain.securite.confirmation_heures')),
            ['utilisateur' => $utilisateur->id, 'hash' => $this->empreinte($utilisateur)],
            absolute: false,
        );

        return $this->racine().$relatif;
    }

    /** L'empreinte (SHA-1 de l'adresse) que le lien doit porter : elle lie le lien à l'adresse actuelle du compte. */
    public function empreinte(User $utilisateur): string
    {
        return sha1(mb_strtolower($utilisateur->email));
    }

    /** Envoie (ou renvoie) le lien de confirmation. Sans effet si l'adresse est déjà confirmée. */
    public function envoyer(User $utilisateur): void
    {
        if ($utilisateur->email_verified_at !== null) {
            return;
        }

        $heures = (int) config('koudmain.securite.confirmation_heures');

        $this->notifications->envoyerTransactionnel(
            $utilisateur->email,
            $utilisateur->prenom,
            'Confirmez votre adresse e-mail',
            "Pour activer votre compte KoudMain, confirmez que cette adresse est bien la vôtre. Le lien est valable $heures heures. "
            .'Si vous n\'avez pas créé de compte, ignorez simplement ce message : rien ne sera activé.',
            $this->lien($utilisateur),
            'Confirmer mon adresse',
        );

        Journal::info('email.confirmation_envoyee', ['utilisateur' => $utilisateur->id]);
    }

    /**
     * Quelqu'un vient de s'inscrire avec l'adresse d'un compte qui existe déjà. Sur la page, la réponse est exactement la même que
     * pour une vraie inscription (règle 16 : on ne révèle pas qu'une adresse est inscrite) ; c'est ce message, envoyé au
     * PROPRIÉTAIRE de l'adresse, qui l'informe.
     */
    public function prevenirCompteExistant(User $utilisateur): void
    {
        if ($utilisateur->email_verified_at === null) {
            // Compte déjà actif par son numéro (application) : le lien n'est PAS envoyé à la demande d'un tiers. Sinon, quiconque
            // lit cette boîte pourrait confirmer l'adresse d'un compte qui a déjà de la valeur (wallet), puis réinitialiser son
            // mot de passe par e-mail. Seul le titulaire, connecté, redemande ce lien (POST /auth/email/send-link).
            if ($utilisateur->telephoneVerifie()) {
                Journal::info('inscription.adresse_non_confirmee_utilisee', ['utilisateur' => $utilisateur->id]);

                return;
            }

            // Le compte attend toujours sa confirmation : le plus utile est de renvoyer le lien.
            $this->envoyer($utilisateur);

            return;
        }

        $this->notifications->envoyerTransactionnel(
            $utilisateur->email,
            $utilisateur->prenom,
            'Vous avez déjà un compte KoudMain',
            'Une inscription vient d\'être demandée avec votre adresse e-mail, mais un compte existe déjà. Connectez-vous avec votre mot de passe habituel. '
            .'Si ce n\'est pas vous qui avez fait cette demande, ignorez ce message : votre compte n\'a pas été modifié.',
            $this->racine().'/connexion',
            'Me connecter',
        );

        Journal::info('inscription.adresse_deja_utilisee', ['utilisateur' => $utilisateur->id]);
    }

    /**
     * Confirme l'adresse. Compte du site : il est activé, et la bienvenue (et l'alerte aux administrateurs) part maintenant.
     * Compte de l'application : il était déjà actif par son numéro (bienvenue déjà envoyée) ; il devient certifié.
     */
    public function confirmer(User $utilisateur): bool
    {
        if ($utilisateur->email_verified_at !== null) {
            return false;
        }

        $dejaActif = $utilisateur->telephoneVerifie();
        $utilisateur->forceFill(['email_verified_at' => now()])->save();

        Journal::info('email.confirme', ['utilisateur' => $utilisateur->id, 'role' => $utilisateur->est_prestataire ? 'prestataire' : 'client']);
        app(Enregistreur::class)->evenement('email.confirme');

        // Bienvenue et, pour un prestataire, alerte des administrateurs : seulement maintenant, pour qu'une adresse fictive ne
        // remplisse pas la file de validation. Déjà faits à la vérification du numéro pour un compte de l'application.
        if (! $dejaActif) {
            $this->ecouteur->inscription($utilisateur);
        }

        return true;
    }

    /** Racine du site : APP_URL en production (un en-tête Host falsifié n'y change rien), sinon l'adresse de la requête. */
    private function racine(): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return $url !== '' ? $url : rtrim(url('/'), '/');
    }
}
