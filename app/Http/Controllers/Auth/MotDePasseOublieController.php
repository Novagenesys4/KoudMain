<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NouveauMotDePasseRequest;
use App\Models\User;
use App\Services\MotDePasseOublieService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mot de passe oublié » (SECURITE.md, règle 19). Comme la confirmation d'adresse (ConfirmationEmailController),
 * le lien reçu par e-mail est SIGNÉ et la demande ne révèle jamais si une adresse est inscrite (règle 16).
 */
class MotDePasseOublieController extends Controller
{
    public function __construct(private readonly MotDePasseOublieService $service)
    {
    }

    public function formulaire(): View
    {
        return view('auth.mot-de-passe-oublie');
    }

    public function envoyer(Request $request): RedirectResponse
    {
        $donnees = $request->validate(['email' => ['bail', 'required', 'string', 'max:150', 'email:rfc']], [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
            'email.max' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
        ]);

        $compte = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($donnees['email']))])->first();

        // Par e-mail, seulement vers une adresse CONFIRMÉE : un compte de l'application dont l'adresse n'est pas confirmée
        // réinitialise son mot de passe par SMS (depuis l'application). Sinon, la personne qui lit une adresse mal saisie
        // pourrait prendre ce compte (et son wallet).
        if ($compte !== null && (! config('koudmain.securite.confirmation_email') || $compte->emailConfirme())) {
            $this->service->envoyer($compte);
        }

        // Toujours la même réponse : elle ne dit pas si l'adresse est inscrite (règle 16).
        return redirect()->route('connexion')->with(
            'succes',
            'Si un compte existe pour cette adresse, un e-mail vient d\'être envoyé pour réinitialiser le mot de passe. '
            .'Pensez à regarder dans vos courriers indésirables.'
        );
    }

    /** Le lien signé (le middleware « signed:relative » a déjà refusé un lien falsifié ou expiré). */
    public function reinitialiser(int $utilisateur, string $hash): View
    {
        $compte = User::query()->find($utilisateur);

        // Compte inconnu, ou empreinte qui ne correspond plus au mot de passe actuel (lien déjà utilisé, ou périmé) : même refus.
        abort_if($compte === null || ! hash_equals($this->service->empreinte($compte), $hash), 403);

        return view('auth.reinitialiser-mot-de-passe', ['utilisateur' => $compte->id, 'hash' => $hash]);
    }

    public function enregistrer(NouveauMotDePasseRequest $request, int $utilisateur, string $hash): RedirectResponse
    {
        $compte = User::query()->find($utilisateur);

        abort_if($compte === null || ! hash_equals($this->service->empreinte($compte), $hash), 403);

        $this->service->reinitialiser($compte, $request->validated('password'));

        return redirect()->route('connexion')->with('succes', 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.');
    }
}
