<?php

namespace App\Http\Requests\Auth;

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
            'email.required' => 'Veuillez saisir votre adresse e-mail.',
            'email.max' => 'Adresse e-mail ou mot de passe incorrect.',
            'password.required' => 'Veuillez saisir votre mot de passe.',
            'password.max' => 'Adresse e-mail ou mot de passe incorrect.',
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
        $email = Saisie::chaine($this->input('email'));
        $ip = ClientIp::resoudre($this);

        $this->verifierLimites($email, $ip);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        // On compare TOUJOURS à un hash, même si le compte n'existe pas.
        $motDePasseCorrect = Hash::check(Saisie::chaine($this->input('password')), $user?->password ?? self::HASH_FACTICE);

        if ($user === null || ! $motDePasseCorrect) {
            $this->enregistrerEchec($email, $ip);
            Journal::info('connexion.echec', ['email' => $email, 'ip' => $ip, 'compte_existe' => $user !== null]);
            app(Enregistreur::class)->evenement('connexion.echec');

            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        // Mot de passe juste, mais l'adresse e-mail n'a jamais été confirmée (règle 19) : on ne connecte pas. Ce message n'est vu que
        // par quelqu'un qui connaît le mot de passe du compte : il ne révèle rien à un curieux. Le formulaire propose de renvoyer le lien.
        if ($user->email_verified_at === null) {
            Journal::info('connexion.email_non_confirme', ['utilisateur' => $user->id, 'ip' => $ip]);

            $this->session()->flash('email_a_confirmer', true);

            throw ValidationException::withMessages([
                'email' => 'Votre adresse e-mail n\'est pas encore confirmée. Ouvrez le message que nous vous avons envoyé et cliquez sur le lien, puis reconnectez-vous.',
            ]);
        }

        // Mot de passe juste, mais le prestataire n'a pas encore été validé par un administrateur.
        if ($user->enAttenteValidation()) {
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
