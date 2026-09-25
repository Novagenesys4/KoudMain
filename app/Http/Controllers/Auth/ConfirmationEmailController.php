<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConfirmationEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirmation de l'adresse e-mail (règle 19) : le lien reçu par e-mail, et le renvoi de ce lien.
 * Aucune de ces deux pages ne révèle si une adresse est inscrite (règle 16).
 */
class ConfirmationEmailController extends Controller
{
    public function __construct(private readonly ConfirmationEmailService $confirmations)
    {
    }

    /** Le lien signé (le middleware « signed:relative » a déjà refusé un lien falsifié ou expiré). */
    public function confirmer(int $utilisateur, string $hash): RedirectResponse
    {
        $compte = User::query()->find($utilisateur);

        // Compte inconnu ou empreinte qui ne correspond plus à l'adresse : même refus dans les deux cas.
        abort_if($compte === null || ! hash_equals($this->confirmations->empreinte($compte), $hash), 403);

        $dejaActif = $compte->telephoneVerifie();

        if (! $this->confirmations->confirmer($compte)) {
            return redirect()->route('connexion')->with('succes', 'Votre adresse est déjà confirmée. Connectez-vous.');
        }

        if ($dejaActif) {
            return redirect()->route('connexion')->with('succes', 'Adresse confirmée ! Votre compte KoudMain est maintenant certifié. Vous pouvez aussi vous connecter avec cette adresse.');
        }

        return redirect()->route('connexion')->with('succes', $compte->enAttenteValidation()
            ? 'Adresse confirmée ! Un administrateur va maintenant vérifier votre profil prestataire ; vous pourrez vous connecter dès sa validation.'
            : 'Adresse confirmée ! Votre compte est activé : vous pouvez vous connecter.');
    }

    public function formulaire(): View
    {
        return view('auth.confirmation-renvoi');
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        $donnees = $request->validate(['email' => ['bail', 'required', 'string', 'max:150', 'email:rfc']], [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
            'email.max' => 'Saisissez une adresse e-mail valide, par exemple nom@exemple.com.',
        ]);

        $compte = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($donnees['email']))])->first();

        // Un compte déjà actif par son numéro (application) redemande son lien depuis son espace, connecté : pas depuis ce formulaire
        // public (voir ConfirmationEmailService::prevenirCompteExistant).
        if ($compte !== null && $compte->email_verified_at === null && ! $compte->telephoneVerifie()) {
            $this->confirmations->envoyer($compte);
        }

        // Toujours la même réponse : elle ne dit pas si l'adresse est inscrite, ni si elle est déjà confirmée.
        return redirect()->route('connexion')->with('succes', 'Si un compte en attente de confirmation existe pour cette adresse, un nouvel e-mail vient d\'être envoyé. Pensez à regarder dans vos courriers indésirables.');
    }
}
