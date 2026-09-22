<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatutCommande;
use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Prestation;
use App\Models\User;
use App\Support\Espace\Compteurs;
use Illuminate\View\View;

/** /admin : les chiffres clés de la plateforme et les profils qui attendent une validation. */
class TableauDeBordController extends Controller
{
    public function __invoke(): View
    {
        // Tous les compteurs en UNE requête (le menu latéral s'en sert aussi).
        $c = Compteurs::admin();

        return view('admin.tableau-de-bord', [
            'clients' => $c['clients'],
            'prestataires' => $c['prestataires'],
            'enAttente' => $c['en_attente'],
            'commandes' => $c['commandes'],
            'litiges' => $c['litiges'],
            'prestations' => Prestation::query()->count(),
            'publiees' => Prestation::query()->visibles()->count(),
            'categories' => $c['categories'],
            'services' => $c['services'],
            'retraitsEnAttente' => $c['retraits'],
            'litigesListe' => Commande::query()->where('statut', StatutCommande::Litige->value)->with(['prestations', 'client', 'prestataire'])->oldest('id')->limit(5)->get(),
            'aValider' => User::query()->where('est_prestataire', true)->where('est_valide', false)->where('est_admin', false)
                ->with(['quartier', 'avatar'])->oldest()->limit(5)->get(),
        ]);
    }
}
