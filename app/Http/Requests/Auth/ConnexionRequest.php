<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Api\ConnexionApiRequest;
use App\Support\Saisie;
use App\Models\User;
use App\Services\Metriques\Enregistreur;
use App\Support\ClientIp;
use App\Support\Journal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion (ex-authentifier() de auth_service.php) : validation, limitation des tentatives,
 * vérification du mot de passe, refus des prestataires non validés, ouverture de session.
 *
 * Identifiant : adresse e-mail OU numéro de téléphone (le champ du formulaire garde le nom « email »). On ne se connecte qu'avec un
 * identifiant VÉRIFIÉ : l'e-mail confirmé par son lien, ou le numéro vérifié par code SMS dans l'application. Un compte créé sur
 * l'application entre donc sur le site avec son numéro tant que son adresse n'est pas confirmée.
 */
class ConnexionRequest extends FormRequest
{
    /** Hash bcrypt d'un mot de passe bidon : comparé quand le compte n'existe pas, pour que
     *  le temps de réponse ne révèle pas si l'adresse e-mail est inscrite. */
    private const HASH_FACTICE = '$2y$12$zlQVmfQIgVGMDmqgQvPvCu3o6ejSwOzj/IHb2/2Qrgp/ykWGuSk86';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Veuillez saisir votre adresse e-mail ou votre numéro de téléphone.',
            'email.max' => 'Identifiant ou mot de passe incorrect.',
            'password.required' => 'Veuillez saisir votre mot de passe.',
            'password.max' => 'Identifiant ou mot de passe incorrect.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim(Saisie::chaine($this->input('email'))))]);
    }

    /**
     * @throws ValidationException si la connexion est refusée (message affiché sous le champ e-mail)
     */
    public function authentifier(): void
    {
        $login = Saisie::chaine($this->input('email'));
        $ip = ClientIp::resoudre($this);

        // Même recherche que l'application (e-mail, ou numéro vérifié). Compteur d'échecs PAR COMPTE (clé = son adresse), partagé
        // avec l'application : changer d'identifiant ou de porte ne contourne pas la limite.
        $user = ConnexionApiRequest::trouver($login);
        $email = $user !== null ? mb_strtolower($user->email) : $login;

        $this->verifierLimites($email, $ip);

        // On compare TOUJOURS à un hash, même si le compte n'existe pas.
        $motDePasseCorrect = Hash::check(Saisie::chaine($this->input('password')), $user?->password ?? self::HASH_FACTICE);

        if ($user === null || ! $motDePasseCorrect) {
            $this->enregistrerEchec($email, $ip);
            Journal::info('connexion.echec', ['email' => $email, 'ip' => $ip, 'compte_existe' => $user !== null]);
            app(Enregistreur::class)->evenement('connexion.echec');

            throw ValidationException::withMessages(['email' => 'Identifiant ou mot de passe incorrect.']);
        }

        // Mot de passe juste, mais l'identifiant saisi n'est pas vérifié (règle 19). Ce message n'est vu que par quelqu'un qui connaît
        // le mot de passe du compte : il ne révèle rien à un curieux.
        if (config('koudmain.securite.confirmation_email') && ! $user->identifiantVerifie($login)) {
            Journal::info('connexion.identifiant_non_verifie', ['utilisateur' => $user->id, 'ip' => $ip]);

            // Compte du site qui n'a encore rien confirmé : le formulaire propose de renvoyer le lien de confirmation.
            if (! $user->aUnIdentifiantVerifie() && str_contains($login, '@')) {
                $this->session()->flash('email_a_confirmer', true);

                throw ValidationException::withMessages([
                    'email' => 'Votre adresse e-mail n\'est pas encore confirmée. Ouvrez le message que nous vous avons envoyé et cliquez sur le lien, puis reconnectez-vous.',
                ]);
            }

            throw ValidationException::withMessages(['email' => match (true) {
                ! $user->aUnIdentifiantVerifie() => 'Votre compte n\'est pas encore vérifié : connectez-vous avec votre adresse e-mail après avoir cliqué sur le lien de confirmation reçu.',
                str_contains($login, '@') => 'Votre adresse e-mail n\'est pas encore confirmée : connectez-vous avec votre numéro de téléphone. Vous pourrez confirmer votre adresse depuis l\'application KoudMain.',
                default => 'Ce numéro n\'est pas encore vérifié : connectez-vous avec votre adresse e-mail.',
            }]);
        }

        // Mot de passe juste, mais le prestataire n'a pas encore été validé par un administrateur.
        // Un client qui a demandé à devenir aussi prestataire (application mobile) garde l'accès à son espace client.
        if ($user->enAttenteValidation() && ! $user->est_client) {
            throw ValidationException::withMessages([
                'email' => "Votre compte prestataire est en attente de validation par l'administrateur. "
                    ."Vous recevrez l'accès dès qu'il sera vérifié.",
            ]);
        }

        // Connexion réussie : on efface les échecs récents de ce compte.
        RateLimiter::clear($this->cleEmail($email));

        // Hash calculé avec un ancien coût/algorithme ? On le recalcule (le cast "hashed" hache tout seul).
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Saisie::chaine($this->input('password'))])->save();
        }

        // « Rester connecté » (règle 9) : 14 jours au plus, et jamais pour un administrateur (son compte peut tout faire :
        // sa session doit expirer, comme celle de tout le monde, après 2 heures d'inactivité).
        $souvenir = $this->boolean('remember') && ! $user->est_admin;
        Auth::guard('web')->setRememberDuration((int) config('koudmain.securite.remember_minutes'));
        Auth::login($user, $souvenir);

        Journal::info('connexion.succes', ['utilisateur' => $user->id, 'ip' => $ip]);
        app(Enregistreur::class)->evenement('connexion.succes');
    }

    private function verifierLimites(string $email, string $ip): void
    {
        $parEmail = RateLimiter::tooManyAttempts($this->cleEmail($email), config('koudmain.auth.max_echecs_par_email'));
        $parIp = RateLimiter::tooManyAttempts($this->cleIp($ip), config('koudmain.auth.max_echecs_par_ip'));

        if ($parEmail || $parIp) {
            Journal::alerte('connexion.bloquee', ['email' => $email, 'ip' => $ip]);
            app(Enregistreur::class)->evenement('connexion.bloquee');

            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.',
            ]);
        }
    }

    private function enregistrerEchec(string $email, string $ip): void
    {
        $fenetre = config('koudmain.auth.fenetre_secondes');

        RateLimiter::hit($this->cleEmail($email), $fenetre);
        RateLimiter::hit($this->cleIp($ip), $fenetre);
    }

    private function cleEmail(string $email): string
    {
        return 'connexion|email|'.sha1($email);
    }

    private function cleIp(string $ip): string
    {
        return 'connexion|ip|'.$ip;
    }
}
