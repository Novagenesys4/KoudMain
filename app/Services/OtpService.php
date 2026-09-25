<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Mail\CodeVerificationMail;
use App\Models\User;
use App\Models\VerificationOtp;
use App\Services\Sms\EnvoiSms;
use App\Services\Sms\SmsJournal;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Codes à 6 chiffres de l'application mobile : inscription, vérification du compte, mot de passe oublié.
 *
 * Canal d'envoi (SMS_DRIVER, config koudmain.api.sms.driver) :
 *  - « journal » : SMS écrit dans storage/logs (développement) ;
 *  - « email »   : le code part par e-mail à l'adresse du compte (en attendant un fournisseur SMS). Il prouve alors l'ADRESSE
 *                  E-MAIL, pas le numéro : c'est l'e-mail qui est marqué confirmé (voir User::compteVerifie) ;
 *  - « aucun »   : aucun code ne part (inscription par l'application fermée).
 *
 * Garanties :
 *  - le code n'est jamais stocké en clair (bcrypt) et n'est valable que quelques minutes, une seule fois ;
 *  - 5 essais au plus par code, puis il faut en redemander un ; un renvoi n'est possible qu'après 30 s ;
 *  - 5 envois au plus par numéro et par heure (le SMS coûte de l'argent et peut servir à harceler quelqu'un) ;
 *  - une demande « à blanc » (sans compte derrière : adresse déjà inscrite, numéro inconnu) répond EXACTEMENT comme une vraie
 *    (règle 16) : même forme, mêmes délais. Aucun SMS ne part et aucun code ne peut la valider.
 */
class OtpService
{
    public function fournisseur(): ?EnvoiSms
    {
        return match (config('koudmain.api.sms.driver')) {
            'journal' => new SmsJournal,
            default => null,
        };
    }

    /** « sms », « email » ou null (aucun envoi possible). */
    public function canal(): ?string
    {
        return self::parEmail() ? 'email' : ($this->fournisseur() !== null ? 'sms' : null);
    }

    /** Les codes partent-ils par e-mail (SMS_DRIVER=email) ? */
    public static function parEmail(): bool
    {
        return config('koudmain.api.sms.driver') === 'email';
    }

    public function actif(): bool
    {
        return $this->canal() !== null;
    }

    /**
     * Ouvre une demande de code et envoie le SMS (sauf demande à blanc, $utilisateur null).
     *
     * @return array{0: VerificationOtp, 1: string|null} la demande, et le code en clair (null pour une demande à blanc)
     *
     * @throws OperationRefusee
     */
    public function demander(string $objet, ?User $utilisateur, string $telephone): array
    {
        $this->verifierActif();
        $this->verifierQuota($telephone);

        $config = config('koudmain.api.otp');
        $verification = new VerificationOtp;
        $verification->forceFill([
            'user_id' => $utilisateur?->id,
            'objet' => $objet,
            'telephone' => $telephone,
            'expire_at' => now()->addMinutes((int) $config['validite_minutes']),
        ]);

        // Une demande à blanc reçoit aussi un code (haché, même coût de calcul) : il n'est ni envoyé, ni renvoyé, ni validable (pas de compte).
        $code = $this->nouveauCode();
        $this->preparerEnvoi($verification, $code);
        $verification->save();

        if ($utilisateur !== null) {
            $this->envoyerCode($utilisateur, $telephone, $code, $objet);
        }

        Journal::info('otp.demande', ['objet' => $objet, 'utilisateur' => $utilisateur?->id, 'a_blanc' => $utilisateur === null]);

        return [$verification, $utilisateur !== null ? $code : null];
    }

    /**
     * Renvoie un NOUVEAU code pour la même demande (l'ancien ne vaut plus rien).
     *
     * @return array{0: VerificationOtp, 1: string|null}
     *
     * @throws OperationRefusee
     */
    public function renvoyer(string $id): array
    {
        $this->verifierActif();
        $verification = $this->trouver($id);

        if ($verification === null || $verification->utilise_at !== null) {
            throw new OperationRefusee('Cette demande de code n\'est plus valable. Recommencez depuis le début.');
        }

        $attente = $this->secondesAvantRenvoi($verification);

        if ($attente > 0) {
            throw new OperationRefusee("Patientez $attente s avant de demander un nouveau code.");
        }

        $this->verifierQuota($verification->telephone);

        $code = $this->nouveauCode();
        $verification->forceFill([
            'tentatives' => 0,
            'expire_at' => now()->addMinutes((int) config('koudmain.api.otp.validite_minutes')),
        ]);
        $this->preparerEnvoi($verification, $code);
        $verification->save();

        $utilisateur = $verification->user_id !== null ? User::query()->find($verification->user_id) : null;

        if ($utilisateur !== null) {
            $this->envoyerCode($utilisateur, $verification->telephone, $code, $verification->objet);
        }

        return [$verification, $verification->user_id !== null ? $code : null];
    }

    /**
     * Vérifie le code. Réussi : la demande est consommée et le compte concerné est renvoyé.
     *
     * @throws OperationRefusee message prêt à afficher
     */
    public function verifier(string $id, string $code, string $objet, ?User $attendu = null): User
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $max = (int) config('koudmain.api.otp.tentatives_max');

        // Le résultat est décidé DANS la transaction (ligne verrouillée : deux essais simultanés comptent pour deux),
        // l'erreur est levée APRÈS : sinon l'annulation de la transaction effacerait aussi le compteur d'essais.
        [$resultat, $utilisateur] = DB::transaction(function () use ($id, $code, $objet, $attendu, $max): array {
            $verification = $this->trouver($id, verrouiller: true);

            if ($verification === null || $verification->objet !== $objet || ($attendu !== null && $verification->user_id !== $attendu->id)) {
                return ['inconnu', null];
            }

            if (! $verification->estUtilisable()) {
                return ['expire', null];
            }

            if ($verification->tentatives >= $max) {
                return ['bloque', null];
            }

            $verification->forceFill(['tentatives' => $verification->tentatives + 1]);

            // Toujours UNE comparaison bcrypt, demande à blanc comprise : le temps de réponse ne révèle rien.
            $bon = Hash::check($code, (string) $verification->code_hash)
                && $verification->user_id !== null
                && strlen($code) === (int) config('koudmain.api.otp.longueur');

            if (! $bon) {
                $verification->save();

                return [$verification->tentatives >= $max ? 'bloque' : 'faux', null];
            }

            $verification->forceFill(['utilise_at' => now()])->save();

            return ['ok', User::query()->findOrFail($verification->user_id)];
        });

        Journal::info('otp.verification', ['objet' => $objet, 'resultat' => $resultat, 'utilisateur' => $utilisateur?->id]);

        return match ($resultat) {
            'ok' => $utilisateur,
            'faux' => throw new OperationRefusee(self::parEmail() ? 'Code incorrect. Vérifiez l\'e-mail reçu et réessayez.' : 'Code incorrect. Vérifiez le SMS et réessayez.'),
            'bloque' => throw new OperationRefusee('Trop d\'essais avec ce code. Demandez un nouveau code.'),
            'expire' => throw new OperationRefusee('Ce code a expiré. Demandez un nouveau code.'),
            default => throw new OperationRefusee('Cette demande de code n\'est plus valable. Recommencez depuis le début.'),
        };
    }

    /**
     * Ce que l'application reçoit après une demande ou un renvoi.
     *  - « canal » : « sms » ou « email » (l'application adapte ses textes) ;
     *  - « email_masque » : seulement quand l'adresse est déjà connue de celui qui demande (celle qu'il vient de saisir, ou
     *    celle de son propre compte). Jamais pour « mot de passe oublié » : elle révélerait qu'un compte existe ;
     *  - « code_debug » : hors production seulement, pour tester sans téléphone ni boîte mail.
     *
     * @return array<string, mixed>
     */
    public function representer(VerificationOtp $verification, ?string $code = null, ?string $email = null): array
    {
        return [
            'verification_id' => $verification->id,
            'canal' => $this->canal(),
            'telephone_masque' => self::masquer($verification->telephone),
            'email_masque' => $email !== null && self::parEmail() ? self::masquerEmail($email) : null,
            'longueur' => (int) config('koudmain.api.otp.longueur'),
            'expire_dans' => max(0, (int) now()->diffInSeconds($verification->expire_at, false)),
            'renvoi_dans' => $this->secondesAvantRenvoi($verification),
        ] + ($code !== null && in_array(config('koudmain.api.sms.driver'), ['journal', 'email'], true) && ! app()->isProduction() ? ['code_debug' => $code] : []);
    }

    /** « awa.diabate@gmail.com » -> « aw•••@gmail.com ». */
    public static function masquerEmail(string $email): string
    {
        [$nom, $domaine] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');

        return mb_substr($nom, 0, 2).'•••@'.$domaine;
    }

    /** « 0708123456 » -> « 07 08 •• •• 56 » (comme le prototype). */
    public static function masquer(string $telephone): string
    {
        $t = str_pad(substr(preg_replace('/\D/', '', $telephone) ?? '', 0, 10), 10, '0');

        return substr($t, 0, 2).' '.substr($t, 2, 2).' •• •• '.substr($t, 8, 2);
    }

    private function trouver(string $id, bool $verrouiller = false): ?VerificationOtp
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            return null;
        }

        $requete = VerificationOtp::query()->whereKey(strtolower($id));

        return $verrouiller ? $requete->lockForUpdate()->first() : $requete->first();
    }

    private function preparerEnvoi(VerificationOtp $verification, string $code): void
    {
        $verification->forceFill([
            'code_hash' => Hash::make($code),
            'envois' => $verification->envois + 1,
            'envoye_at' => now(),
        ]);
    }

    private function secondesAvantRenvoi(VerificationOtp $verification): int
    {
        if ($verification->envoye_at === null) {
            return 0;
        }

        $possible = $verification->envoye_at->copy()->addSeconds((int) config('koudmain.api.otp.renvoi_secondes'));

        return max(0, (int) ceil(now()->diffInSeconds($possible, false)));
    }

    private function nouveauCode(): string
    {
        $longueur = (int) config('koudmain.api.otp.longueur');

        return str_pad((string) random_int(0, 10 ** $longueur - 1), $longueur, '0', STR_PAD_LEFT);
    }

    private function envoyerCode(User $utilisateur, string $telephone, string $code, string $objet): void
    {
        $minutes = (int) config('koudmain.api.otp.validite_minutes');

        if (self::parEmail()) {
            $pourquoi = $objet === VerificationOtp::MOT_DE_PASSE ? 'choisir un nouveau mot de passe' : 'vérifier votre compte';

            // Envoi immédiat (pas de file d'attente : le code est attendu tout de suite). Un échec du serveur d'e-mails est
            // journalisé mais ne change PAS la réponse (règle 16 : une demande à blanc et une vraie répondent pareil) ;
            // la personne peut redemander un code 30 s plus tard.
            try {
                Mail::to($utilisateur->email)->send(new CodeVerificationMail((string) $utilisateur->prenom, $code, $pourquoi, $minutes));
            } catch (Throwable $e) {
                Log::error('otp.email.echec', ['utilisateur' => $utilisateur->id, 'erreur' => $e->getMessage()]);
            }

            return;
        }

        $pourquoi = $objet === VerificationOtp::MOT_DE_PASSE ? 'pour choisir un nouveau mot de passe' : 'pour vérifier votre numéro';

        $this->fournisseur()?->envoyer($telephone, "KoudMain : votre code est $code ($pourquoi). Valable $minutes min. Ne le communiquez à personne.");
    }

    /** @throws OperationRefusee */
    private function verifierActif(): void
    {
        if (! $this->actif()) {
            throw new OperationRefusee('La vérification par code n\'est pas encore activée sur KoudMain. Réessayez bientôt.');
        }
    }

    /** @throws OperationRefusee */
    private function verifierQuota(string $telephone): void
    {
        $envois = (int) VerificationOtp::query()
            ->where('telephone', $telephone)
            ->where('envoye_at', '>=', now()->subHour())
            ->sum('envois');

        if ($envois >= (int) config('koudmain.api.otp.envois_max_par_heure')) {
            throw new OperationRefusee('Trop de codes demandés pour ce numéro. Réessayez dans une heure.');
        }
    }
}
