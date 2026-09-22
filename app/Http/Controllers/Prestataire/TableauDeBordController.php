<?php

namespace App\Http\Controllers\Prestataire;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Escrow;
use App\Support\Espace\Compteurs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** /prestataire : la vue d'ensemble du prestataire (solde, revenus, prestations, note, activité récente). */
class TableauDeBordController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $notes = DB::table('avis')
            ->join('prestations', 'prestations.id', '=', 'avis.prestation_id')
            ->where('prestations.prestataire_id', $user->id)
            ->selectRaw('ROUND(AVG(avis.note)::numeric, 1) as moyenne, count(*) as total')
            ->first();

        $prestations = Compteurs::prestations($user);

        $commandes = Commande::query()->where('prestataire_id', $user->id);

        return view('prestataire.tableau-de-bord', [
            'user' => $user,
            'solde' => Compteurs::solde($user),
            // « Encaissé » = déjà versé sur le wallet (séquestre libéré) ; « à recevoir » = payé par le client, pas encore libéré.
            'revenus' => (float) Escrow::query()->where('prestataire_id', $user->id)->where('statut', Escrow::LIBERE)->sum('montant'),
            'aRecevoir' => (float) Escrow::query()->where('prestataire_id', $user->id)->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])->sum('montant'),
            'enAttente' => Compteurs::commandesEnAttentePrestataire($user),
            'nbPrestations' => $prestations['total'],
            'nbPubliees' => $prestations['publiees'],
            'noteMoyenne' => (int) $notes->total > 0 ? (float) $notes->moyenne : null,
            'nbAvis' => (int) $notes->total,
            'recentes' => (clone $commandes)->with(['prestations', 'client'])->latest()->limit(4)->get(),
        ]);
    }
}
