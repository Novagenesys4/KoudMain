<?php

namespace App\Services;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Journal;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * « Mot de passe oublié » (SECURITE.md, règles 16 et 19) : réutilise le même mécanisme de lien signé que la
 * confirmation d'adresse e-mail (App\Services\ConfirmationEmailService), plutôt que la table password_reset_tokens
 * de Laravel (elle reste créée par la migration d'origine, mais n'est pas utilisée ici).
 *
 * Le lien :
 *  - est signé (HMAC avec APP_KEY) : le modifier, ou en fabriquer un, est impossible ;
 *  - expire (config koudmain.securite.reinitialisation_minutes) ;
 *  - contient l'empreinte du mot de passe haché ACTUEL : dès que le mot de passe change (donc dès que le lien a
 *    servi une fois), l'empreinte ne correspond plus, et le lien ne peut pas être rejoué. Pas besoin d'une table
 *    de jetons à usage unique séparée ;
 *  - est construit à partir d'APP_URL, jamais de l'en-tête Host de la requête (falsifiable) ;
 *  - ne CONNECTE personne : il permet seulement de choisir un nouveau mot de passe, puis renvoie vers la connexion.
 */
class MotDePasseOublieService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** L'adresse (absolue) du lien de réinitialisation de ce compte. */
    public function lien(User $utilisateur): string
    {
        $relatif = URL::temporarySignedRoute(
            'mot-de-passe-oublie.reinitialiser',
            now()->addMinutes((int) config('koudmain.securite.reinitialisation_minutes')),
            ['utilisateur' => $utilisateur->id, 'hash' => $this->empreinte($utilisateur)],
            absolute: false,
        );

        return $this->racine().$relatif;
    }

    /** Empreinte liée au mot de passe ACTUEL (SHA-1 du hachage bcrypt) : change dès que le mot de passe est modifié. */
    public function empreinte(User $utilisateur): string
    {
        return sha1($utilisateur->password);
    }

    /** Envoie le lien de réinitialisation. Toujours appelé seulement si le compte existe (règle 16 : décidé par l'appelant). */
    public function envoyer(User $utilisateur): void
    {
        $minutes = (int) config('koudmain.securite.reinitialisation_minutes');

        $this->notifications->envoyerTransactionnel(
            $utilisateur->email,
            $utilisateur->prenom,
            'Réinitialisez votre mot de passe',
            "Vous avez demandé la réinitialisation de votre mot de passe KoudMain. Le lien est valable $minutes minutes et ne sert qu'une fois. "
            .'Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement ce message : rien ne sera changé.',
            $this->lien($utilisateur),
            'Choisir un nouveau mot de passe',
        );

        Journal::info('mot_de_passe.lien_envoye', ['utilisateur' => $utilisateur->id]);
    }

    /**
     * Enregistre le nouveau mot de passe. Le jeton « rester connecté » est aussi renouvelé : un cookie « remember »
     * dérobé avant la réinitialisation cesse de fonctionner (les sessions déjà ouvertes ailleurs, elles, suivent la
     * même limite que le changement de mot de passe classique : voir SECURITE.md, règle 9).
     */
    public function reinitialiser(User $utilisateur, string $nouveauMotDePasse): void
    {
        $utilisateur->forceFill([
            'password' => $nouveauMotDePasse,
            'remember_token' => Str::random(60),
        ])->save();

        Journal::info('mot_de_passe.reinitialise', ['utilisateur' => $utilisateur->id]);
    }

    /** Racine du site : APP_URL en production (un en-tête Host falsifié n'y change rien), sinon l'adresse de la requête. */
    private function racine(): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return $url !== '' ? $url : rtrim(url('/'), '/');
    }
}
