<?php

namespace App\Http\Controllers;

use App\Models\Paiement;
use App\Services\Paiement\PaiementCinetPay;
use App\Services\PaiementService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Les deux adresses appelées par l'agrégateur de paiement :
 *  - retour       : le client revient de la page de paiement (son navigateur) ;
 *  - notification : l'agrégateur prévient notre serveur (webhook).
 * Aucune des deux ne CROIT ce qu'elle reçoit : PaiementService::confirmer() redemande l'état réel à l'agrégateur
 * et ne crédite qu'une seule fois, quel que soit le nombre d'appels.
 */
class PaiementRetourController extends Controller
{
    public function retour(Request $request, PaiementService $paiements): RedirectResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur === null) {
            return redirect()->route('connexion');
        }

        // Seuls les clients et les prestataires ont un portefeuille : un administrateur qui tombe ici est renvoyé vers son tableau de bord.
        $route = $utilisateur->aLeRole('client') ? 'client.wallet' : ($utilisateur->aLeRole('prestataire') ? 'prestataire.wallet' : $utilisateur->espace().'.tableau-de-bord');
        $reference = $this->reference($request, 'transaction_id', 'cpm_trans_id');

        // On ne traite qu'un paiement qui appartient à l'utilisateur connecté.
        $paiement = $reference !== '' ? Paiement::query()->where('reference', $reference)->where('user_id', $utilisateur->id)->first() : null;

        if ($paiement === null) {
            return redirect()->route($route);
        }

        $paiement = $paiements->confirmer($paiement->reference);

        return match ($paiement->statut) {
            Paiement::REUSSI => redirect()->route($route)->with('succes', 'Recharge de '.Format::fcfa($paiement->montant).' reçue. Merci !'),
            Paiement::EN_ATTENTE => redirect()->route($route)->with('succes', 'Votre paiement est en cours de vérification : le solde sera mis à jour dès que l\'opérateur confirme (quelques instants).'),
            default => redirect()->route($route)->with('erreur', 'Le paiement n\'a pas abouti'.($paiement->motif_echec ? ' ('.$paiement->motif_echec.')' : '').'. Rien n\'a été débité.'),
        };
    }

    /** Petite étape intermédiaire : envoie le client sur la page de paiement de l'agrégateur (voir WalletController::recharger). */
    public function continuer(Request $request, string $reference): View
    {
        $paiement = Paiement::query()->where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();

        abort_unless($paiement->statut === Paiement::EN_ATTENTE && $paiement->url_paiement, 404);

        return view('espace.paiement-redirection', ['paiement' => $paiement]);
    }

    public function notification(Request $request, PaiementService $paiements): Response
    {
        // Signature (x-token) de CinetPay : une notification qui ne la porte pas n'est pas traitée. Le retour du navigateur et le
        // rattrapage planifié créditent de toute façon un vrai paiement.
        if (config('koudmain.paiement.driver') === 'cinetpay' && ! PaiementCinetPay::jetonValide($request)) {
            Log::warning('paiement.notification_signature_invalide', ['reference' => $this->reference($request, 'cpm_trans_id', 'transaction_id'), 'ip' => $request->ip()]);

            return response('SIGNATURE', 403);
        }

        $reference = $this->reference($request, 'cpm_trans_id', 'transaction_id');

        if ($reference !== '' && Paiement::query()->where('reference', $reference)->exists()) {
            try {
                $paiements->confirmer($reference);
            } catch (\Throwable $e) {
                Log::error('paiement.notification_echec', ['reference' => $reference, 'erreur' => $e->getMessage()]);

                return response('ERREUR', 500); // l'agrégateur réessaiera
            }
        }

        return response('OK'); // toujours 200 pour une référence inconnue : on ne renseigne pas un curieux
    }

    /**
     * La référence reçue, si c'est bien un texte court de la forme attendue (lettres majuscules et chiffres). Un tableau
     * (?transaction_id[]=x), un texte immense ou tout autre format est ignoré : rien n'atteint la base ni les journaux.
     */
    private function reference(Request $request, string ...$champs): string
    {
        foreach ($champs as $champ) {
            $valeur = $request->input($champ);

            if (is_string($valeur) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $valeur) === 1) {
                return $valeur;
            }
        }

        return '';
    }
}
