<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Models\Retrait;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /admin/retraits : les demandes de retrait des prestataires. L'administrateur fait le virement, puis le confirme (ou le refuse). */
class RetraitController extends Controller
{
    public function index(Request $request): View
    {
        $statut = in_array($request->query('statut'), [Retrait::EN_ATTENTE, Retrait::EFFECTUE, Retrait::REFUSE], true) ? $request->query('statut') : null;

        $requete = Retrait::query()->with('user')->latest('id');

        if ($statut !== null) {
            $requete->where('statut', $statut);
        }

        $compteurs = Retrait::query()->toBase()->selectRaw('statut, count(*) as n, sum(montant) as total')->groupBy('statut')->get()->keyBy('statut');

        return view('admin.retraits', [
            'retraits' => $requete->paginate(15)->withQueryString(),
            'statut' => $statut,
            'compteurs' => $compteurs,
        ]);
    }

    public function confirmer(Request $request, Retrait $retrait, WalletService $wallets): RedirectResponse
    {
        try {
            $wallets->confirmerRetrait($request->user(), $retrait);
        } catch (OperationRefusee $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Retrait confirmé : le prestataire a été payé.');
    }

    public function refuser(Request $request, Retrait $retrait, WalletService $wallets): RedirectResponse
    {
        $donnees = $request->validate(['motif' => ['required', 'string', 'max:300']], ['motif.required' => 'Indiquez le motif du refus.', 'motif.max' => 'Le motif ne peut pas dépasser 300 caractères.']);

        try {
            $wallets->refuserRetrait($request->user(), $retrait, $donnees['motif']);
        } catch (OperationRefusee $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Retrait refusé : l\'argent est revenu sur le wallet du prestataire.');
    }
}
