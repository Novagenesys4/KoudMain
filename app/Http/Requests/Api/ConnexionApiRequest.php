<?php

namespace App\Http\Requests\Api;

use App\Exceptions\RefusApi;
use App\Models\User;
use App\Services\Metriques\Enregistreur;
use App\Services\OtpService;
use App\Support\ClientIp;
use App\Support\Journal;
use App\Support\Saisie;
use App\Support\TelephoneCI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion depuis l'application : identifiant = adresse e-mail OU numéro de téléphone (comme le prototype), plus le mot de passe.
 *
 * Mêmes protections que le site (ConnexionRequest) : échecs comptés par compte ET par IP (les compteurs sont PARTAGÉS avec le
 * site : on ne contourne pas la limite en changeant de porte), comparaison bcrypt même si le compte n'existe pas, message
 * identique quelle que soit la raison de l'échec (règle 16), et on ne se connecte qu'avec un identifiant VÉRIFIÉ : le numéro
 * (code SMS) ou l'adresse e-mail (lien de confirmation). Un compte créé sur l'application se connecte donc avec son numéro tant
 * que son e-mail n'est pas confirmé.
 *
 * Différences voulues avec le site :
 *  - un prestataire pas encore validé PEUT se connecter : l'application lui montre l'état de sa vérification (écran KYC envoyé) ;
 *    toutes les actions de prestataire lui restent refusées (middleware api.role, code « prestataire_non_valide ») ;
 *  - un administrateur ne se connecte pas à l'application : l'administration se fait sur le site.
 */
class ConnexionApiRequest extends FormRequest
{
    private const HASH_FACTICE = '$2y$12$zlQVmfQIgVGMDmqgQvPvCu3o6ejSwOzj/IHb2/2Qrgp/ykWGuSk86';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['login' => trim(Saisie::chaine($this->input('login')))]);
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Saisissez votre numéro de téléphone ou votre adresse e-mail.',
            'login.max' => 'Identifiant ou mot de passe incorrect.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.max' => 'Identifiant ou mot de passe incorrect.',
        ];
    }

    /**
     * @throws ValidationException|RefusApi
     */
    public function authentifier(): User
    {
        $login = Saisie::chaine($this->input('login'));
        $ip = ClientIp::resoudre($this);
        $utilisateur = self::trouver($login);
        $cle = $utilisateur !== null ? 'connexion|email|'.sha1(mb_strtolower($utilisateur->email)) : 'connexion|email|'.sha1(mb_strtolower($login));

        if (RateLimiter::tooManyAttempts($cle, (int) config('koudmain.auth.max_echecs_par_email'))
            || RateLimiter::tooManyAttempts('connexion|ip|'.$ip, (int) config('koudmain.auth.max_echecs_par_ip'))) {
            Journal::alerte('api.connexion.bloquee', ['ip' => $ip]);

            throw ValidationException::withMessages(['login' => 'Trop de tentatives de connexion. Réessayez dans 15 minutes.']);
        }

        $correct = Hash::check(Saisie::chaine($this->input('password')), $utilisateur?->password ?? self::HASH_FACTICE);

        if ($utilisateur === null || ! $correct) {
            $fenetre = (int) config('koudmain.auth.fenetre_secondes');
            RateLimiter::hit($cle, $fenetre);
            RateLimiter::hit('connexion|ip|'.$ip, $fenetre);
            Journal::info('api.connexion.echec', ['ip' => $ip, 'compte_existe' => $utilisateur !== null]);
            app(Enregistreur::class)->evenement('connexion.echec');

            throw ValidationException::withMessages(['login' => 'Identifiant ou mot de passe incorrect.']);
        }

        if ($utilisateur->est_admin) {
            throw new RefusApi('Les comptes administrateurs se connectent sur le site web KoudMain.', 403, 'admin_non_autorise');
        }

        // Mot de passe juste, mais l'identifiant saisi n'est pas vérifié (ex. : e-mail pas encore confirmé). Ce message n'est vu que
        // par quelqu'un qui connaît le mot de passe du compte : il ne révèle rien à un curieux.
        if (config('koudmain.securite.confirmation_email') && ! $utilisateur->identifiantVerifie($login)) {
            Journal::info('api.connexion.identifiant_non_verifie', ['utilisateur' => $utilisateur->id, 'ip' => $ip]);

            throw new RefusApi(match (true) {
                ! $utilisateur->aUnIdentifiantVerifie() => 'Votre compte n\'est pas encore vérifié : touchez « Mot de passe oublié » pour recevoir un code '.(OtpService::parEmail() ? 'par e-mail' : 'par SMS').' et l\'activer.',
                str_contains($login, '@') => 'Votre adresse e-mail n\'est pas encore confirmée : connectez-vous avec votre numéro de téléphone. Vous pourrez confirmer votre adresse depuis votre profil.',
                default => 'Votre numéro de téléphone n\'est pas encore vérifié : connectez-vous avec votre adresse e-mail, puis vérifiez votre numéro.',
            }, 403, 'identifiant_non_verifie');
        }

        RateLimiter::clear($cle);

        if (Hash::needsRehash($utilisateur->password)) {
            $utilisateur->forceFill(['password' => Saisie::chaine($this->input('password'))])->save();
        }

        Journal::info('api.connexion.succes', ['utilisateur' => $utilisateur->id, 'ip' => $ip]);
        app(Enregistreur::class)->evenement('connexion.succes');

        return $utilisateur;
    }

    /**
     * Le compte désigné par un e-mail ou un numéro. Un numéro partagé par plusieurs comptes (la base ne l'interdit pas) désigne
     * le SEUL compte où il est vérifié (un numéro ne peut être vérifié que sur un compte, voir AuthController) ; sinon personne.
     */
    public static function trouver(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])->first();
        }

        $telephone = TelephoneCI::normaliser($login);

        if (! TelephoneCI::estValide($telephone)) {
            return null;
        }

        $verifies = User::query()->where('telephone', $telephone)->whereNotNull('telephone_verifie_at')->limit(2)->get();

        if ($verifies->isNotEmpty()) {
            return $verifies->count() === 1 ? $verifies->first() : null;
        }

        $comptes = User::query()->where('telephone', $telephone)->limit(2)->get();

        return $comptes->count() === 1 ? $comptes->first() : null;
    }
}
