<?php

namespace App\Http\Controllers\Espace;

use App\Http\Controllers\Controller;
use App\Support\Espace\Bientot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Les onglets de l'espace dont le fonctionnement arrive dans un prochain lot (voir App\Support\Espace\Bientot).
 * La page à afficher se déduit du NOM de la route : une seule classe pour tous ces onglets.
 */
class BientotController extends Controller
{
    public function __invoke(Request $request): View
    {
        $page = Bientot::page((string) $request->route()?->getName());
        abort_if($page === null, 404);

        $action = $page['action'] !== null
            ? ['href' => route($page['action']['route']), 'libelle' => $page['action']['libelle']]
            : ['href' => route($request->user()->routeTableauDeBord()), 'libelle' => 'Retour à la vue d\'ensemble'];

        return view('espace.bientot', ['page' => $page, 'action' => $action]);
    }
}
