<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OperationRefusee;
use App\Exceptions\RefusApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConnexionApiRequest;
use App\Http\Requests\Api\InscriptionApiRequest;
use App\Http\Requests\Api\ProfilApiRequest;
use App\Http\Resources\Api\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Models\VerificationOtp;
use App\Services\ConfirmationEmailService;
use App\Services\MotDePasseOublieService;
use App\Services\Notifications\Ecouteur;
use App\Services\OtpService;
use App\Support\Journal;
use App\Support\Saisie;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentification de l'application mobile (jetons Sanctum).
 *
 *   Inscription  : POST /auth/register  → code SMS  → POST /auth/register/verify  → jeton (l'e-mail n'est PAS exigé :
 *                  le lien de confirmation part, et le confirmer « certifie » le compte : POST /auth/email/send-link pour le renvoyer)
 *   Connexion    : POST /auth/login → jeton (un compte au numéro non vérifié passe ensuite par /auth/telephone/*)
 *   Mot de passe : POST /auth/password/forgot → code SMS → POST /auth/password/reset
 *
 * Règle 16 (ne jamais révéler qu'un compte existe) : l'inscription avec une adresse déjà inscrite et le mot de passe oublié
 * d'un compte inconnu répondent exactement comme une vraie demande. Le propriétaire de l'adresse est prévenu par e-mail.
 */
class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    // ------------------------------------------------------------------ Inscription

    public function register(InscriptionApiRequest $request, ConfirmationEmailService $confirmations): JsonResponse
    {
        $this->exigerSms();

        $donnees = $request->validated();
        $existant = $this->parEmail($donnees['email']);
        $confirmationActive = (bool) config('koudmain.securite.confirmation_email');
        $nouveau = null;

        if ($existant === null) {
            try {
                $nouveau = $this->creerCompte($donnees, $donnees['role'] === 'prestataire');
            } catch (UniqueConstraintViolationException) {
                $existant = $this->parEmail($donnees['email']);
            }
        }

        if ($nouveau === null) {
            Hash::make($donnees['password']); // même coût de calcul qu'une vraie inscription

            if ($existant !== null && $confirmationActive) {
                $confirmations->prevenirCompteExistant($existant);
            }
        } else {
            // Le lien de confirmation de l'adresse part une fois le numéro vérifié (verifyRegistration), pas avant.
            Journal::info('api.inscription', ['role' => $donnees['role'], 'utilisateur' => $nouveau->id]);
        }

        // Demande à blanc (compte existant) : aucun SMS, même réponse.
        [$verification, $code] = $this->otp->demander(VerificationOtp::INSCRIPTION, $nouveau, $donnees['telephone']);

        return ApiResponse::succes(
            // L'adresse masquée est celle que la personne vient de saisir : elle ne révèle rien.
            $this->otp->representer($verification, $code, $donnees['email']),
            OtpService::parEmail()
                ? 'Un code de vérification a été envoyé par e-mail à '.OtpService::masquerEmail($donnees['email']).'.'
                : 'Un code de vérification a été envoyé par SMS au '.OtpService::masquer($donnees['telephone']).'.',
            202,
        );
    }

    /**
     * Étape 2 de l'inscription : le code SMS. Le numéro vérifié suffit à activer le compte : le jeton est TOUJOURS remis.
     * L'adresse e-mail n'est pas exigée : son lien de confirmation part maintenant, et la confirmer certifie le compte (badge).
     */
    public function verifyRegistration(Request $request, ConfirmationEmailService $confirmations, Ecouteur $ecouteur): JsonResponse
    {
        $donnees = $this->validerCode($request);
        $utilisateur = $this->otp->verifier($donnees['verification_id'], $donnees['code'], VerificationOtp::INSCRIPTION);

        try {
            $this->marquerVerifie($utilisateur);
        } catch (RefusApi $e) {
            // Numéro déjà vérifié sur un autre compte : ce compte tout neuf est retiré, sinon il bloquerait l'adresse e-mail saisie.
            $this->supprimerInscriptionInachevee($utilisateur);

            throw $e;
        }

        // Le compte est actif : bienvenue (et, pour un prestataire, alerte des administrateurs).
        $ecouteur->inscription($utilisateur);

        // Codes par e-mail : l'adresse vient d'être prouvée, aucun lien de confirmation à envoyer.
        if (config('koudmain.securite.confirmation_email') && ! $utilisateur->emailConfirme()) {
            $confirmations->envoyer($utilisateur);
        }

        return ApiResponse::succes(
            $this->ouvrirSession($utilisateur, $donnees['device_name'] ?? null) + ['email_a_confirmer' => ! $utilisateur->emailConfirme()],
            OtpService::parEmail() ? 'Compte vérifié. Bienvenue sur KoudMain !' : 'Numéro vérifié. Bienvenue sur KoudMain !',
        );
    }

    // ------------------------------------------------------------------ Connexion

    public function login(ConnexionApiRequest $request): JsonResponse
    {
        $utilisateur = $request->authentifier();

        return ApiResponse::succes($this->ouvrirSession($utilisateur, $request->validated('device_name')), 'Connexion réussie.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::succes(new UserResource($request->user()));
    }

    /**
     * PUT /auth/me — « Informations personnelles » (Phase 10) : prénom, nom, quartier, e-mails de notification,
     * présentation (prestataires). Le numéro et l'e-mail ne changent pas ici (voir ProfilApiRequest).
     */
    public function update(ProfilApiRequest $request): JsonResponse
    {
        $utilisateur = $request->user();
        $donnees = $request->validated();

        $utilisateur->fill(array_intersect_key($donnees, array_flip(['prenom', 'nom', 'quartier_id', 'bio'])));
        if (array_key_exists('notifications_email', $donnees)) {
            $utilisateur->forceFill(['notifications_email' => (bool) $donnees['notifications_email']]);
        }
        $utilisateur->save();

        Journal::info('api.profil.modifie', ['utilisateur' => $utilisateur->id, 'champs' => array_keys($utilisateur->getChanges())]);

        return ApiResponse::succes(new UserResource($utilisateur->refresh()), 'Vos informations sont enregistrées.');
    }

    /** Déconnecte CET appareil (son jeton est supprimé). */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::succes(null, 'Vous êtes déconnecté.');
    }

    /** Déconnecte TOUS les appareils de la personne (téléphone perdu...). */
    public function logoutAll(Request $request): JsonResponse
    {
        $nombre = $request->user()->tokens()->delete();
        Journal::info('api.deconnexion_partout', ['utilisateur' => $request->user()->id, 'jetons' => $nombre]);

        return ApiResponse::succes(null, 'Vous êtes déconnecté de tous vos appareils.');
    }

    // ------------------------------------------------------------------ Vérification du numéro (compte existant)

    public function sendPhoneCode(Request $request): JsonResponse
    {
        $this->exigerSms();
        $utilisateur = $request->user();

        if ($utilisateur->compteVerifie()) {
            throw new OperationRefusee(OtpService::parEmail() ? 'Votre compte est déjà vérifié.' : 'Votre numéro est déjà vérifié.');
        }

        [$verification, $code] = $this->otp->demander(VerificationOtp::TELEPHONE, $utilisateur, $utilisateur->telephone);

        return ApiResponse::succes(
            $this->otp->representer($verification, $code, $utilisateur->email),
            OtpService::parEmail()
                ? 'Un code de vérification a été envoyé par e-mail à '.OtpService::masquerEmail($utilisateur->email).'.'
                : 'Un code de vérification a été envoyé par SMS au '.OtpService::masquer($utilisateur->telephone).'.',
            202,
        );
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        $donnees = $this->validerCode($request);
        $this->otp->verifier($donnees['verification_id'], $donnees['code'], VerificationOtp::TELEPHONE, $request->user());
        $this->marquerVerifie($request->user());

        return ApiResponse::succes(new UserResource($request->user()), OtpService::parEmail() ? 'Compte vérifié.' : 'Numéro vérifié.');
    }

    // ------------------------------------------------------------------ Certification : confirmation de l'adresse e-mail

    /** Envoie (ou renvoie) le lien de confirmation de l'adresse e-mail du compte connecté. Le confirmer certifie le compte. */
    public function sendEmailLink(Request $request, ConfirmationEmailService $confirmations): JsonResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur->emailConfirme()) {
            throw new OperationRefusee('Votre adresse e-mail est déjà confirmée : votre compte est certifié.');
        }

        if (! config('koudmain.securite.confirmation_email')) {
            throw new RefusApi('L\'envoi d\'e-mails n\'est pas encore activé sur KoudMain. Réessayez bientôt.', 503, 'email_indisponible');
        }

        $confirmations->envoyer($utilisateur);

        return ApiResponse::succes(
            null,
            'Un lien de confirmation vient d\'être envoyé à '.$utilisateur->email.'. Pensez à regarder dans vos courriers indésirables.',
        );
    }

    /** Renvoi d'un code (inscription, numéro ou mot de passe) : possible 30 s après le précédent. */
    public function resendCode(Request $request): JsonResponse
    {
        $donnees = $request->validate(['verification_id' => ['required', 'string', 'max:64']], ['verification_id.*' => 'Demande de code invalide.']);
        [$verification, $code] = $this->otp->renvoyer($donnees['verification_id']);

        // Pas d'adresse ici : l'application garde celle de la première demande (un renvoi à blanc ne la connaît pas).
        return ApiResponse::succes($this->otp->representer($verification, $code), OtpService::parEmail() ? 'Nouveau code envoyé par e-mail.' : 'Nouveau code envoyé par SMS.');
    }

    // ------------------------------------------------------------------ Mot de passe oublié

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->exigerSms();

        $donnees = $request->validate(
            ['login' => ['required', 'string', 'max:150']],
            ['login.*' => 'Saisissez votre numéro de téléphone ou votre adresse e-mail.'],
        );

        $utilisateur = ConnexionApiRequest::trouver(trim(Saisie::chaine($donnees['login'])));

        // Un administrateur passe par le site. Compte inconnu ou non autorisé : demande à blanc, même réponse.
        $cible = $utilisateur !== null && ! $utilisateur->est_admin ? $utilisateur : null;
        // Demande à blanc : un numéro fictif mais STABLE pour cet identifiant (le plafond d'envois par numéro s'applique pareil).
        $telephone = $cible?->telephone ?? '07'.str_pad((string) (crc32(mb_strtolower($donnees['login'])) % 100_000_000), 8, '0', STR_PAD_LEFT);

        [$verification, $code] = $this->otp->demander(VerificationOtp::MOT_DE_PASSE, $cible, $telephone);

        // Le numéro (même masqué) n'est pas renvoyé ici : il révélerait qu'un compte existe.
        return ApiResponse::succes(
            array_merge($this->otp->representer($verification, $code), ['telephone_masque' => null]),
            OtpService::parEmail()
                ? 'Si un compte correspond, un code vient d\'être envoyé à son adresse e-mail.'
                : 'Si un compte correspond, un code vient d\'être envoyé par SMS au numéro enregistré.',
            202,
        );
    }

    public function resetPassword(Request $request, MotDePasseOublieService $motsDePasse): JsonResponse
    {
        $mdp = 'Choisissez un mot de passe de 8 caractères minimum, avec au moins une lettre et un chiffre.';
        $donnees = $request->validate([
            'verification_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
        ], [
            'verification_id.*' => 'Demande de code invalide.',
            'code.*' => 'Saisissez les 6 chiffres du code reçu.',
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
            'password.*' => $mdp,
        ]);

        $utilisateur = $this->otp->verifier($donnees['verification_id'], $donnees['code'], VerificationOtp::MOT_DE_PASSE);

        DB::transaction(function () use ($utilisateur, $donnees, $motsDePasse): void {
            $motsDePasse->reinitialiser($utilisateur, $donnees['password']);
            // Recevoir le code prouve la possession du numéro (ou de l'adresse) ; tous les appareils connectés sont déconnectés.
            $this->marquerVerifie($utilisateur);
            $utilisateur->tokens()->delete();
        });

        return ApiResponse::succes(null, 'Mot de passe modifié. Connectez-vous avec votre nouveau mot de passe.');
    }

    // ------------------------------------------------------------------ Outils

    /** @return array{token: string, token_type: string, expire_le: string|null, user: UserResource} */
    private function ouvrirSession(User $utilisateur, ?string $appareil): array
    {
        $appareil = trim(mb_substr(Saisie::chaine($appareil), 0, 100)) ?: 'Application mobile';
        $expiration = now()->addDays((int) config('koudmain.api.jeton_jours'));
        $jeton = $utilisateur->createToken($appareil, ['*'], $expiration);

        return [
            'token' => $jeton->plainTextToken,
            'token_type' => 'Bearer',
            'expire_le' => $expiration->toIso8601String(),
            'user' => new UserResource($utilisateur->refresh()),
        ];
    }

    /** @return array{verification_id: string, code: string, device_name?: string|null} */
    private function validerCode(Request $request): array
    {
        return $request->validate([
            'verification_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'verification_id.*' => 'Demande de code invalide.',
            'code.*' => 'Saisissez les 6 chiffres du code reçu.',
        ]);
    }

    /**
     * Code accepté : marque ce qu'il prouve. Par SMS, le numéro ; par e-mail (SMS_DRIVER=email), l'ADRESSE e-mail — jamais le
     * numéro, qui n'a pas été prouvé (sinon n'importe qui pourrait « s'approprier » le numéro de quelqu'un d'autre).
     *
     * @throws RefusApi
     */
    private function marquerVerifie(User $utilisateur): void
    {
        if (! OtpService::parEmail()) {
            $this->marquerTelephoneVerifie($utilisateur);

            return;
        }

        if ($utilisateur->email_verified_at === null) {
            $utilisateur->forceFill(['email_verified_at' => now()])->save();
        }
    }

    /**
     * Le numéro devient un identifiant de connexion : il ne peut être vérifié que sur UN compte (sinon « se connecter avec son
     * numéro » ne désignerait plus personne). Ce refus n'est montré qu'à quelqu'un qui vient de recevoir le code : il prouve
     * déjà posséder ce numéro.
     *
     * @throws RefusApi
     */
    private function marquerTelephoneVerifie(User $utilisateur): void
    {
        if ($utilisateur->telephone_verifie_at !== null) {
            return;
        }

        $dejaPris = User::query()
            ->where('telephone', $utilisateur->telephone)
            ->whereKeyNot($utilisateur->getKey())
            ->whereNotNull('telephone_verifie_at')
            ->exists();

        if ($dejaPris) {
            Journal::info('api.telephone.deja_utilise', ['utilisateur' => $utilisateur->id]);

            throw new RefusApi(
                'Ce numéro est déjà vérifié sur un autre compte KoudMain. Connectez-vous avec ce compte (ou « Mot de passe oublié »), ou utilisez un autre numéro.',
                409,
                'telephone_deja_utilise',
            );
        }

        $utilisateur->forceFill(['telephone_verifie_at' => now()])->save();
    }

    /** Inscription abandonnée (aucun identifiant vérifié, rien d'autre en base que son wallet vide) : le compte est retiré. */
    private function supprimerInscriptionInachevee(User $utilisateur): void
    {
        if ($utilisateur->aUnIdentifiantVerifie()) {
            return;
        }

        Journal::info('api.inscription.annulee', ['utilisateur' => $utilisateur->id, 'raison' => 'telephone_deja_utilise']);
        $utilisateur->delete(); // wallet et codes : suppression en cascade (clés étrangères)
    }

    /** @throws RefusApi */
    private function exigerSms(): void
    {
        if (! $this->otp->actif()) {
            throw new RefusApi('La vérification par code n\'est pas encore activée sur KoudMain. Réessayez bientôt ou utilisez le site web.', 503, 'sms_indisponible');
        }
    }

    private function parEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    /** Même création que le site (InscriptionController) : rôles fixés côté serveur, wallet à zéro. @param array<string, mixed> $donnees */
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

            $user->forceFill([
                'est_client' => ! $estPrestataire,
                'est_prestataire' => $estPrestataire,
                'est_admin' => false,
                'est_valide' => ! $estPrestataire,
                // Adresse non confirmée : le compte s'active par son numéro (code SMS) ; confirmer l'adresse le certifiera.
                'email_verified_at' => null,
            ])->save();

            $user->wallet()->create();

            return $user;
        });
    }
}
