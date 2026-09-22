<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\InscriptionRequest;
use App\Models\User;
use App\Services\ConfirmationEmailService;
use App\Support\Journal;
use App\Support\Referentiel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class InscriptionController extends Controller
{
    public function afficher(): View
    {
        $villes = Referentiel::villes();

        return view('auth.inscription', [
            // Les quartiers sont présentés groupés par ville (liste gardée en cache : elle ne change presque jamais).
            'villes' => $villes,
            // Après un aller-retour de validation (ex. mot de passe refusé à l'étape 3), on sait déjà quelle ville
            // correspond au quartier choisi : la 2ᵉ étape se rouvre pré-remplie plutôt que vide.
            'villeSelectionneeId' => $this->trouverVille($villes, old('quartier_id')),
        ]);
    }

    /** @param \Illuminate\Support\Collection<int, \App\Models\Ville> $villes */
    private function trouverVille($villes, mixed $quartierId): ?int
    {
        if ($quartierId === null || $quartierId === '') {
            return null;
        }

        return $villes->first(fn ($ville) => $ville->quartiers->contains('id', (int) $quartierId))?->id;
    }

    /**
     * Inscription : le compte est créé « en attente » et un e-mail de confirmation part vers l'adresse saisie (règle 19).
     * Le compte ne s'active, et l'accueil de bienvenue ne part, qu'après un clic sur ce lien (ConfirmationEmailController).
     *
     * La réponse est IDENTIQUE que l'adresse soit nouvelle ou déjà inscrite (règle 16) : sinon la page d'inscription servirait à
     * savoir qui a un compte. Dans le second cas, c'est le propriétaire de l'adresse qui est prévenu, par e-mail.
     */
    public function creer(InscriptionRequest $request, ConfirmationEmailService $confirmations): RedirectResponse
    {
        $donnees = $request->validated();
        $estPrestataire = $donnees['role'] === 'prestataire';

        $existant = $this->chercher($donnees['email']);
        $confirmationActive = (bool) config('koudmain.securite.confirmation_email');

        if ($existant === null) {
            try {
                $user = $this->creerCompte($donnees, $estPrestataire);
            } catch (UniqueConstraintViolationException) {
                // Deux inscriptions simultanées avec la même adresse : l'autre a gagné, ce cas devient « adresse déjà inscrite ».
                $existant = $this->chercher($donnees['email']);
            }
        }

        if ($existant !== null) {
            // Même coût de calcul que pour une vraie inscription : le temps de réponse ne trahit rien non plus.
            Hash::make($donnees['password']);
            if ($confirmationActive) {
                $confirmations->prevenirCompteExistant($existant);
            }
        } else {
            Journal::info('inscription', ['role' => $donnees['role'], 'email' => $user->email, 'utilisateur' => $user->id]);
            if ($confirmationActive) {
                $confirmations->envoyer($user);
            }
        }

        // Sans confirmation par e-mail (EMAIL_CONFIRMATION=false) : le compte est actif tout de suite. Le message reste le même que
        // l'adresse soit nouvelle ou déjà inscrite (règle 16) : il ne révèle pas qui possède un compte.
        if (! $confirmationActive) {
            return redirect()->route('connexion')->with('succes', 'Votre compte est créé. Connectez-vous avec votre adresse e-mail et votre mot de passe'
                .($estPrestataire ? ' : un administrateur doit encore valider votre profil prestataire avant votre première connexion' : '')
                .'.');
        }

        return redirect()->route('connexion')->with('succes', 'Presque terminé ! Si cette adresse peut être utilisée, un e-mail de confirmation vient d\'y être envoyé : ouvrez-le et cliquez sur le lien pour activer votre compte'
            .($estPrestataire ? ' (un administrateur vérifiera ensuite votre profil prestataire)' : '')
            .'. Pensez à regarder dans vos courriers indésirables.');
    }

    private function chercher(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    /** @param array<string, mixed> $donnees */
    private function creerCompte(array $donnees, bool $estPrestataire): User
    {
        return DB::transaction(function () use ($donnees, $estPrestataire): User {
            $user = new User([
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => $donnees['email'],
                'telephone' => $donnees['telephone'],
                'password' => $donnees['password'],
                'quartier_id' => $donnees['quartier_id'],
            ]);

            // Les rôles ne sont pas "fillable" : on les fixe ici, côté serveur, jamais depuis le formulaire.
            // Un client est actif dès la confirmation de son adresse ; un prestataire attend en plus la validation d'un administrateur.
            // L'adresse n'est PAS confirmée (email_verified_at reste vide) : la connexion est refusée tant qu'elle ne l'est pas.
            $user->forceFill([
                'est_client' => ! $estPrestataire,
                'est_prestataire' => $estPrestataire,
                'est_admin' => false,
                'est_valide' => ! $estPrestataire,
                'email_verified_at' => config('koudmain.securite.confirmation_email') ? null : now(),
            ])->save();

            $user->wallet()->create(); // solde à 0

            return $user;
        });
    }
}
