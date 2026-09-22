<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /tableau-de-bord : renvoie chacun vers son espace (client, prestataire ou administrateur).
 * Le contenu de chaque espace est dans App\Http\Controllers\{Client,Prestataire,Admin}\TableauDeBordController.
 */
class TableauDeBordController extends Controller
{
    public function rediriger(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->routeTableauDeBord());
    }
}
