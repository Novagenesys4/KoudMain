<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PaiementResource;
use App\Http\Resources\Api\RetraitResource;
use App\Http\Resources\Api\TransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Escrow;
use App\Models\Paiement;
use App\Models\Retrait;
use App\Models\WalletTransaction;
use App\Services\PaiementService;
use App\Services\WalletService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le wallet : solde, séquestre, historique ; recharge par Mobile Money (client) ; demande de retrait (prestataire).
 * L'argent ne bouge QUE dans WalletService et PaiementService (verrous, registre immuable, montants en centimes).
 *
 * Paiement CinetPay, côté application :
 *   1. POST /wallet/recharges → { reference, statut: en_attente, url_paiement } ;
 *   2. l'application ouvre url_paiement (WebView) ; le client paie (Orange Money, MTN MoMo, Wave) ;
 *   3. CinetPay prévient le serveur sur /paiements/notification (signature vérifiée) : le wallet est crédité UNE fois ;
 *   4. l'application interroge GET /wallet/recharges/{reference} (toutes les 3 s, 2 min au plus) jusqu'à « reussi » ou « echoue ».
 * Le client n'est JAMAIS cru sur parole : chaque vérification redemande l'état réel à CinetPay.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets, private readonly PaiementService $paiements) {}

    public function show(Request $request): JsonResponse
    {
        $moi = $request->user();
        $wallet = $this->wallets->pour($moi);
        // Le séquestre affiché dépend de l'espace ouvert dans l'application (en-tête X-Espace) : un compte à double rôle
        // voit ce qu'il a bloqué comme client, ou ce qui lui sera versé comme prestataire.
        $colonne = $moi->espaceDemande($request->header('X-Espace')) === 'prestataire' ? 'prestataire_id' : 'client_id';

        return ApiResponse::succes([
            'solde' => (int) round((float) $wallet->solde),
            // Client : son argent bloqué dans des commandes en cours. Prestataire : ce qui lui sera versé à la validation des clients.
            'en_sequestre' => (int) round((float) Escrow::query()->where($colonne, $moi->id)->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])->sum('montant')),
            'devise' => config('koudmain.devise'),
            'peut_recharger' => $moi->aLeRole('client'),
            'peut_retirer' => $moi->aLeRole('prestataire'),
            'recharge_active' => $this->paiements->actif(),
            'recharges_en_attente' => PaiementResource::collection(
                Paiement::query()->where('user_id', $moi->id)->where('statut', Paiement::EN_ATTENTE)->latest('id')->limit(3)->get(),
            )->resolve(),
        ]);
    }

    /** GET /wallet/transactions?type=credit|debit|retrait&sens=entree|sortie&page= */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->wallets->pour($request->user());
        $requete = WalletTransaction::query()->where('wallet_id', $wallet->id)->latest('id');

        if (in_array($type = $request->query('type'), ['credit', 'debit', 'retrait'], true)) {
            $requete->where('type', $type);
        }

        match ($request->query('sens')) {
            'entree' => $requete->where('type', 'credit'),
            'sortie' => $requete->where('type', '!=', 'credit'),
            default => null,
        };

        return ApiResponse::pagine($requete->paginate(20), TransactionResource::class);
    }

    /** POST /wallet/recharges { montant, methode: "Orange Money"|"MTN MoMo"|"Wave", telephone? } */
    public function recharger(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'montant' => ['required', 'integer', 'min:1', 'max:'.config('koudmain.finance.mouvement_max')],
            'methode' => ['required', 'string', 'max:40'],
            'telephone' => ['nullable', 'string', 'max:25'],
        ], [
            'montant.*' => 'Indiquez un montant entier en FCFA (minimum '.Format::fcfa(config('koudmain.finance.recharge_min')).').',
            'methode.*' => 'Choisissez un moyen de paiement.',
            'telephone.*' => 'Le numéro Mobile Money n\'est pas valide.',
        ]);

        $paiement = $this->paiements->recharger($request->user(), $donnees['montant'], $donnees['methode'], $donnees['telephone'] ?? null);

        $message = match ($paiement->statut) {
            Paiement::REUSSI => 'Recharge de '.Format::fcfa($paiement->montant).' reçue'.($this->paiements->simulation() ? ' (simulation : aucun argent réel).' : '.'),
            Paiement::EN_ATTENTE => 'Confirmez le paiement de '.Format::fcfa($paiement->montant).' sur la page '.$paiement->methode.'.',
            default => $paiement->motif_echec ?: 'Le paiement n\'a pas abouti. Rien n\'a été débité.',
        };

        if ($paiement->statut === Paiement::ECHOUE) {
            return ApiResponse::erreur($message, 422, 'paiement_echoue', data: (new PaiementResource($paiement))->resolve());
        }

        return ApiResponse::succes(new PaiementResource($paiement), $message, 201, ['solde' => (int) round($this->wallets->solde($request->user()))]);
    }

    /** GET /wallet/recharges/{reference} : l'état réel, redemandé à l'agrégateur (crédit une seule fois, quel que soit le nombre d'appels). */
    public function recharge(Request $request, string $reference): JsonResponse
    {
        $paiement = Paiement::query()->where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        $paiement = $this->paiements->confirmer($paiement->reference);

        $message = match ($paiement->statut) {
            Paiement::REUSSI => 'Recharge de '.Format::fcfa($paiement->montant).' reçue. Merci !',
            Paiement::EN_ATTENTE => 'Paiement en cours de vérification.',
            default => 'Le paiement n\'a pas abouti'.($paiement->motif_echec ? ' ('.$paiement->motif_echec.')' : '').'. Rien n\'a été débité.',
        };

        return ApiResponse::succes(new PaiementResource($paiement), $message, 200, ['solde' => (int) round($this->wallets->solde($request->user()))]);
    }

    /** POST /wallet/retraits { montant, methode: "Orange Money"|"MTN MoMo"|"Wave"|"Virement bancaire", destination } (prestataire). */
    public function retirer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'montant' => ['required', 'integer', 'min:1', 'max:'.config('koudmain.finance.mouvement_max')],
            'methode' => ['required', 'string', 'max:40'],
            'destination' => ['required', 'string', 'max:60'],
        ], [
            'montant.*' => 'Indiquez un montant entier en FCFA.',
            'methode.*' => 'Choisissez un mode de retrait.',
            'destination.*' => 'Indiquez le numéro ou le RIB qui doit recevoir l\'argent.',
        ]);

        $retrait = $this->wallets->demanderRetrait($request->user(), $donnees['montant'], $donnees['methode'], $donnees['destination']);

        return ApiResponse::succes(
            new RetraitResource($retrait),
            'Demande de retrait de '.Format::fcfa($retrait->montant).' enregistrée. Le virement est effectué sous 24 à 48 h.',
            201,
            ['solde' => (int) round($this->wallets->solde($request->user()))],
        );
    }

    public function retraits(Request $request): JsonResponse
    {
        return ApiResponse::pagine(Retrait::query()->where('user_id', $request->user()->id)->latest('id')->paginate(20), RetraitResource::class);
    }
}
