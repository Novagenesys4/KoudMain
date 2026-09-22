<?php

namespace App\Services;

use App\Events\RetraitDemande;
use App\Events\RetraitTraite;
use App\Exceptions\OperationRefusee;
use App\Exceptions\SoldeInsuffisant;
use App\Models\CarteVirtuelle;
use App\Models\Retrait;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Argent;
use App\Support\Format;
use App\Support\TelephoneCI;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Le wallet : le SEUL endroit du code qui fait bouger un solde.
 *
 * Règles (reprises de l'ancien wallet_service.php, en plus strict) :
 *  - chaque mouvement se fait dans une transaction, la ligne du wallet verrouillée (FOR UPDATE) : deux opérations
 *    simultanées ne peuvent pas se marcher dessus ;
 *  - le solde ne devient jamais négatif (la base le refuse aussi : CHECK solde >= 0) ;
 *  - chaque mouvement laisse une ligne immuable dans le registre (wallet_transactions) avec le solde résultant ;
 *  - aucun montant n'est jamais lu comme un flottant pour un calcul : tout passe en centimes (App\Support\Argent).
 */
class WalletService
{
    /** Le wallet de l'utilisateur ; créé à la volée s'il n'existe pas encore (compte ancien, import...). */
    public function pour(User $utilisateur): Wallet
    {
        $wallet = Wallet::query()->where('user_id', $utilisateur->id)->first();

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            $wallet = new Wallet();
            $wallet->forceFill(['user_id' => $utilisateur->id])->save();
        } catch (UniqueConstraintViolationException) {
            // Une autre requête l'a créé entre-temps : c'est très bien, on prend le sien.
        }

        return Wallet::query()->where('user_id', $utilisateur->id)->firstOrFail();
    }

    public function solde(User $utilisateur): float
    {
        return (float) (Wallet::query()->where('user_id', $utilisateur->id)->value('solde') ?? 0);
    }

    /**
     * Ajoute une ligne au registre et déplace le solde. À appeler seul ou depuis une transaction plus large
     * (la commande, le séquestre...) : les verrous durent jusqu'à la fin de la transaction extérieure.
     *
     * @param  'credit'|'debit'|'retrait'  $type
     *
     * @throws SoldeInsuffisant
     */
    public function mouvement(User $utilisateur, string $type, float|int|string $montant, string $libelle, ?int $commandeId = null, ?int $carteId = null): WalletTransaction
    {
        if (! in_array($type, ['credit', 'debit', 'retrait'], true)) {
            throw new InvalidArgumentException("Type de mouvement inconnu : $type");
        }

        $centimes = Argent::centimes($montant);

        if ($centimes <= 0) {
            throw new InvalidArgumentException('Le montant d\'un mouvement doit être positif.');
        }

        return DB::transaction(function () use ($utilisateur, $type, $centimes, $libelle, $commandeId, $carteId): WalletTransaction {
            $this->pour($utilisateur); // s'assure que la ligne existe avant de la verrouiller
            $wallet = Wallet::query()->where('user_id', $utilisateur->id)->lockForUpdate()->firstOrFail();

            $actuel = Argent::centimes($wallet->solde);
            $apres = $type === 'credit' ? $actuel + $centimes : $actuel - $centimes;

            if ($apres < 0) {
                throw new SoldeInsuffisant($centimes / 100, $actuel / 100);
            }

            Wallet::query()->whereKey($wallet->id)->update(['solde' => Argent::decimal($apres), 'updated_at' => now()]);

            $transaction = new WalletTransaction([
                'type' => $type,
                'montant' => Argent::decimal($centimes),
                'libelle' => mb_substr($libelle, 0, 200),
                'solde_apres' => Argent::decimal($apres),
                'commande_id' => $commandeId,
                'carte_id' => $carteId,
            ]);
            $transaction->forceFill(['wallet_id' => $wallet->id])->save();

            return $transaction;
        });
    }

    // ------------------------------------------------------------------ Retraits

    /**
     * Un prestataire demande à retirer de l'argent. Le montant quitte son solde TOUT DE SUITE (il ne peut pas le
     * dépenser deux fois) ; un administrateur effectue ensuite le virement et le confirme, ou le refuse (l'argent est rendu).
     *
     * @throws OperationRefusee
     */
    public function demanderRetrait(User $prestataire, float|int|string $montant, string $methode, string $destination, ?CarteVirtuelle $carte = null): Retrait
    {
        if (! $prestataire->aLeRole('prestataire')) {
            throw new OperationRefusee('Seuls les prestataires peuvent retirer de l\'argent.');
        }

        $centimes = Argent::centimes($montant);
        $min = (int) config('koudmain.finance.retrait_min');
        $max = (int) config('koudmain.finance.mouvement_max');

        if ($centimes < $min * 100) {
            throw new OperationRefusee('Le retrait minimum est de '.Format::fcfa($min).'.');
        }

        if ($centimes > $max * 100) {
            throw new OperationRefusee('Le retrait maximum est de '.Format::fcfa($max).'.');
        }

        if (! in_array($methode, config('koudmain.finance.methodes_retrait'), true)) {
            throw new OperationRefusee('Choisissez un mode de retrait proposé.');
        }

        $destination = $this->destinationValide($methode, $destination);

        return DB::transaction(function () use ($prestataire, $centimes, $methode, $destination, $carte): Retrait {
            $transaction = $this->mouvement($prestataire, 'retrait', $centimes / 100, "Retrait vers $methode ($destination)", null, $carte?->id);

            $retrait = new Retrait();
            $retrait->forceFill([
                'user_id' => $prestataire->id,
                'montant' => Argent::decimal($centimes),
                'methode' => $methode,
                'destination' => $destination,
                'statut' => Retrait::EN_ATTENTE,
                'transaction_id' => $transaction->id,
            ])->save();

            Log::info('retrait.demande', ['retrait' => $retrait->id, 'prestataire' => $prestataire->id, 'montant' => $centimes / 100]);
            DB::afterCommit(fn () => event(new RetraitDemande($retrait)));

            return $retrait;
        });
    }

    /** L'administrateur a fait le virement : le retrait est terminé (le solde était déjà débité). */
    public function confirmerRetrait(User $admin, Retrait $retrait): Retrait
    {
        return $this->traiterRetrait($admin, $retrait, true, null);
    }

    /** Le retrait est refusé : l'argent revient sur le solde du prestataire. */
    public function refuserRetrait(User $admin, Retrait $retrait, string $motif): Retrait
    {
        $motif = trim($motif);

        if ($motif === '') {
            throw new OperationRefusee('Indiquez le motif du refus : il sera communiqué au prestataire.');
        }

        return $this->traiterRetrait($admin, $retrait, false, mb_substr($motif, 0, 300));
    }

    private function traiterRetrait(User $admin, Retrait $retrait, bool $effectue, ?string $motif): Retrait
    {
        if (! $admin->aLeRole('admin')) {
            throw new OperationRefusee('Action réservée aux administrateurs.');
        }

        $traite = DB::transaction(function () use ($admin, $retrait, $effectue, $motif): Retrait {
            $verrouille = Retrait::query()->whereKey($retrait->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== Retrait::EN_ATTENTE) {
                throw new OperationRefusee('Ce retrait a déjà été traité.');
            }

            if (! $effectue) {
                $prestataire = User::query()->findOrFail($verrouille->user_id);
                $this->mouvement($prestataire, 'credit', $verrouille->montant, 'Retrait refusé : montant remboursé sur votre wallet');
            }

            $verrouille->forceFill([
                'statut' => $effectue ? Retrait::EFFECTUE : Retrait::REFUSE,
                'motif_refus' => $motif,
                'traite_par' => $admin->id,
                'traite_at' => now(),
            ])->save();

            Log::info($effectue ? 'retrait.effectue' : 'retrait.refuse', ['retrait' => $verrouille->id, 'admin' => $admin->id]);

            return $verrouille;
        });

        DB::afterCommit(fn () => event(new RetraitTraite($traite)));

        return $traite;
    }

    /** Numéro mobile ivoirien (10 chiffres) pour un opérateur, RIB/IBAN (10 à 34 caractères) pour un virement. */
    private function destinationValide(string $methode, string $destination): string
    {
        if ($methode === 'Virement bancaire') {
            $rib = strtoupper(preg_replace('/[\s\-]/', '', $destination) ?? '');

            if (preg_match('/^[A-Z0-9]{10,34}$/', $rib) !== 1) {
                throw new OperationRefusee('Le RIB ou l\'IBAN doit contenir entre 10 et 34 lettres et chiffres.');
            }

            return $rib;
        }

        $telephone = TelephoneCI::normaliser($destination);

        if (! TelephoneCI::estValide($telephone)) {
            throw new OperationRefusee('Le numéro Mobile Money doit comporter 10 chiffres (ex. 07 01 02 03 04).');
        }

        return $telephone;
    }
}
