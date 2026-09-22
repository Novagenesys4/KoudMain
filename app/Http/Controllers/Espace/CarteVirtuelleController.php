<?php

namespace App\Http\Controllers\Espace;

use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\EnregistrerCarteRequest;
use App\Services\CarteVirtuelleService;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Les cartes virtuelles, côté client et côté prestataire (le rôle vient de la route : « client » ou « prestataire »).
 * Une carte n'est jamais lue par son numéro d'identifiant sans vérifier qu'elle appartient à l'utilisateur connecté :
 * c'est le service qui s'en charge (une carte étrangère répond « introuvable »).
 */
class CarteVirtuelleController extends Controller
{
    public function __construct(private readonly CarteVirtuelleService $cartes)
    {
    }

    public function creer(EnregistrerCarteRequest $request): RedirectResponse
    {
        $role = $this->role($request);

        try {
            $carte = $this->cartes->ajouter($request->user(), $request->carte());
        } catch (OperationRefusee $e) {
            Journal::info('carte.refusee', ['utilisateur' => $request->user()->id, 'motif' => $e->getMessage()]);

            // On rejoue le formulaire SANS le numéro ni le code de sécurité (withInput() seul les mettrait dans la session).
            return redirect()->route("$role.wallet")->withInput($request->except(['numero_carte', 'cvv']))->with('erreur', $e->getMessage())->with('ouvrir', 'carte');
        }

        return redirect()->route("$role.wallet", ['carte' => $carte->id])->with('succes', "Carte « {$carte->libelle} » ajoutée.");
    }

    public function geler(Request $request, int $carte): RedirectResponse|JsonResponse
    {
        $role = $this->role($request);

        try {
            $modifiee = $this->cartes->basculerGel($request->user(), $carte);
        } catch (OperationRefusee $e) {
            return $request->expectsJson()
                ? response()->json(['erreur' => $e->getMessage()], 422)
                : redirect()->route("$role.wallet")->with('erreur', $e->getMessage());
        }

        $message = $modifiee->est_gelee
            ? "Carte « {$modifiee->libelle} » gelée : elle ne peut plus payer, recharger ni retirer."
            : "Carte « {$modifiee->libelle} » réactivée.";

        // L'îlot du wallet appelle cette adresse en JSON pour animer le gel sans recharger la page.
        if ($request->expectsJson()) {
            return response()->json(['id' => $modifiee->id, 'gelee' => $modifiee->est_gelee, 'message' => $message]);
        }

        return redirect()->route("$role.wallet", ['carte' => $modifiee->id])->with('succes', $message);
    }

    public function supprimer(Request $request, int $carte): RedirectResponse
    {
        $role = $this->role($request);

        try {
            $this->cartes->supprimer($request->user(), $carte);
        } catch (OperationRefusee $e) {
            return redirect()->route("$role.wallet")->with('erreur', $e->getMessage());
        }

        return redirect()->route("$role.wallet")->with('succes', 'Carte supprimée. Votre historique est conservé.');
    }

    private function role(Request $request): string
    {
        return (string) $request->route()->defaults['role'];
    }
}
