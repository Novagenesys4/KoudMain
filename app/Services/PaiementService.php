<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Paiement;
use App\Models\User;
use App\Services\Paiement\FournisseurPaiement;
use App\Services\Paiement\PaiementCinetPay;
use App\Services\Paiement\PaiementSimulation;
use App\Support\Argent;
use App\Support\Format;
use App\Support\Journal;
use App\Support\TelephoneCI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recharger son wallet par Mobile Money.
 *
 * Un paiement est créé « en attente », puis confirmé UNE seule fois : confirmer() verrouille la ligne, ne fait rien
 * si le paiement n'est plus « en attente » (double notification, retour du navigateur ET webhook...) et vérifie
 * auprès de l'agrégateur que le montant réellement payé est bien celui demandé avant de créditer.
 */
class PaiementService
{
    public function __construct(private readonly WalletService $wallets, private readonly CarteVirtuelleService $cartes)
    {
    }

    public function fournisseur(): ?FournisseurPaiement
    {
        return match (config('koudmain.paiement.driver')) {
            'simulation' => new PaiementSimulation(),
            'cinetpay' => new PaiementCinetPay(),
            default => null,
        };
    }

    public function actif(): bool
    {
        return $this->fournisseur() !== null;
    }

    public function simulation(): bool
    {
        return config('koudmain.paiement.driver') === 'simulation';
    }

    /** @throws OperationRefusee */
    public function recharger(User $utilisateur, float|int|string $montant, string $methode, ?string $telephone = null, ?int $carteId = null): Paiement
    {
        $fournisseur = $this->fournisseur();

        if ($fournisseur === null) {
            throw new OperationRefusee('La recharge en ligne n\'est pas encore activée sur KoudMain. Réessayez bientôt.');
        }

        $centimes = Argent::centimes($montant);
        $min = (int) config('koudmain.finance.recharge_min');
        $max = (int) config('koudmain.finance.mouvement_max');

        if ($centimes % 100 !== 0) {
            throw new OperationRefusee('Le montant doit être un nombre entier de FCFA.');
        }

        if ($centimes < $min * 100) {
            throw new OperationRefusee('La recharge minimum est de '.Format::fcfa($min).'.');
        }

        if ($centimes > $max * 100) {
            throw new OperationRefusee('La recharge maximum est de '.Format::fcfa($max).'.');
        }

        if (! in_array($methode, config('koudmain.finance.methodes_recharge'), true)) {
            throw new OperationRefusee('Choisissez un moyen de paiement proposé.');
        }

        $telephone = $telephone !== null && trim($telephone) !== '' ? TelephoneCI::normaliser($telephone) : null;

        if ($methode !== 'Carte bancaire' && ! TelephoneCI::estValide($telephone ?? $utilisateur->telephone)) {
            throw new OperationRefusee('Indiquez le numéro '.$methode.' à débiter (10 chiffres).');
        }

        // Une carte n'est demandée que pour un paiement par carte bancaire (elle doit être à vous et non gelée) ;
        // avec Mobile Money, l'argent vient du téléphone, pas d'une carte.
        $carte = $methode === 'Carte bancaire' ? $this->cartes->utilisable($utilisateur, $carteId) : null;

        $paiement = new Paiement();
        $paiement->forceFill([
            'user_id' => $utilisateur->id,
            'carte_id' => $carte?->id,
            'reference' => 'KM'.now()->format('ymdHis').Str::upper(Str::random(6)),
            'fournisseur' => $fournisseur->nom(),
            'methode' => $methode,
            'montant' => Argent::decimal($centimes),
            'statut' => Paiement::EN_ATTENTE,
            'telephone' => $telephone ?? TelephoneCI::normaliser($utilisateur->telephone),
        ])->save();

        $resultat = $fournisseur->initier($paiement, $utilisateur);

        $paiement->forceFill([
            'reference_fournisseur' => $resultat->referenceFournisseur,
            'url_paiement' => $resultat->url,
        ])->save();

        Journal::info('paiement.initie', ['paiement' => $paiement->id, 'fournisseur' => $fournisseur->nom(), 'montant' => $centimes / 100, 'statut' => $resultat->statut]);

        if ($resultat->statut === Paiement::ECHOUE) {
            $this->marquer($paiement, Paiement::ECHOUE, $resultat->motif);
        } elseif ($resultat->statut === Paiement::REUSSI) {
            return $this->confirmer($paiement->reference);
        }

        return $paiement->fresh();
    }

    /**
     * Interroge l'agrégateur puis crédite le wallet, une seule fois. Peut être appelé autant de fois que l'on veut.
     */
    public function confirmer(string $reference): Paiement
    {
        $paiement = Paiement::query()->where('reference', $reference)->firstOrFail();

        $fournisseur = $this->fournisseur();

        if ($fournisseur === null || $fournisseur->nom() !== $paiement->fournisseur) {
            return $paiement; // ce paiement a été ouvert chez un autre agrégateur : on n'y touche pas
        }

        // L'appel réseau se fait AVANT la transaction : on ne garde pas un verrou pendant qu'on attend un serveur distant.
        $resultat = $paiement->statut === Paiement::EN_ATTENTE ? $fournisseur->verifier($paiement) : null;

        return DB::transaction(function () use ($paiement, $resultat): Paiement {
            $verrouille = Paiement::query()->whereKey($paiement->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== Paiement::EN_ATTENTE || $resultat === null) {
                return $verrouille;
            }

            if ($resultat->statut === Paiement::ECHOUE) {
                return $this->marquer($verrouille, Paiement::ECHOUE, $resultat->motif);
            }

            if ($resultat->statut !== Paiement::REUSSI) {
                return $verrouille; // toujours en attente
            }

            if ($resultat->montant !== null && Argent::centimes($resultat->montant) !== Argent::centimes($verrouille->montant)) {
                Journal::erreur('paiement.montant_different', ['paiement' => $verrouille->id, 'demande' => (string) $verrouille->montant, 'paye' => $resultat->montant]);

                return $this->marquer($verrouille, Paiement::ECHOUE, 'Le montant payé ne correspond pas au montant demandé.');
            }

            $utilisateur = User::query()->findOrFail($verrouille->user_id);
            $transaction = $this->wallets->mouvement($utilisateur, 'credit', $verrouille->montant, 'Recharge via '.$verrouille->methode, null, $verrouille->carte_id);

            $verrouille->forceFill([
                'statut' => Paiement::REUSSI,
                'reussi_at' => now(),
                'transaction_id' => $transaction->id,
                'reference_fournisseur' => $resultat->referenceFournisseur ?? $verrouille->reference_fournisseur,
            ])->save();

            Journal::info('paiement.reussi', ['paiement' => $verrouille->id, 'montant' => (string) $verrouille->montant]);

            return $verrouille;
        });
    }

    /**
     * Filet de sécurité (tâche planifiée, toutes les 5 minutes) : revérifie auprès de l'agrégateur les recharges restées « en
     * attente » (notification perdue, client parti avant le retour...). Une recharge jamais finalisée est abandonnée après
     * `expiration_heures` : rien n'a été débité.
     *
     * @return array{verifiees: int, creditees: int, abandonnees: int}
     */
    public function rattraper(): array
    {
        $fournisseur = $this->fournisseur();
        $bilan = ['verifiees' => 0, 'creditees' => 0, 'abandonnees' => 0];

        if ($fournisseur === null) {
            return $bilan;
        }

        $limite = now()->subMinutes((int) config('koudmain.paiement.rattrapage_apres_minutes', 2));
        $expiration = now()->subHours((int) config('koudmain.paiement.expiration_heures', 48));

        $references = Paiement::query()
            ->where('statut', Paiement::EN_ATTENTE)->where('fournisseur', $fournisseur->nom())->where('created_at', '<=', $limite)
            ->orderBy('id')->limit(100)->pluck('reference');

        foreach ($references as $reference) {
            $paiement = $this->confirmer($reference);
            $bilan['verifiees']++;

            if ($paiement->statut === Paiement::REUSSI) {
                $bilan['creditees']++;
            } elseif ($paiement->statut === Paiement::EN_ATTENTE && $paiement->created_at <= $expiration) {
                $this->marquer($paiement, Paiement::ANNULE, 'Paiement non finalisé.');
                $bilan['abandonnees']++;
            }
        }

        return $bilan;
    }

    private function marquer(Paiement $paiement, string $statut, ?string $motif): Paiement
    {
        $paiement->forceFill(['statut' => $statut, 'motif_echec' => $motif !== null ? mb_substr($motif, 0, 200) : null])->save();
        Journal::info('paiement.'.$statut, ['paiement' => $paiement->id, 'motif' => $motif]);

        return $paiement;
    }
}
