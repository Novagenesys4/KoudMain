<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Prestation;
use App\Support\Espace\Compteurs;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /client : la vue d'ensemble du client (chiffres clés, commandes récentes, nouveautés du catalogue). */
class TableauDeBordController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // Les compteurs sont calculés une seule fois pour toute la page (le menu latéral s'en sert aussi).
        $chiffres = Compteurs::commandesClient($user);

        return view('client.tableau-de-bord', [
            'user' => $user,
            'solde' => Compteurs::solde($user),
            'total' => $chiffres['total'],
            'terminees' => $chiffres['terminees'],
            'enAttente' => $chiffres['en_attente'],
            'recentes' => Commande::query()->where('client_id', $user->id)->with(['prestations', 'prestataire'])->latest()->limit(4)->get(),
            'nouveautes' => Prestation::query()->visibles()->with(['prestataire', 'service', 'medias'])->latest()->limit(4)->get(),
        ]);
    }
}
