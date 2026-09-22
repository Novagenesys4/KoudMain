<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConnexionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ConnexionController extends Controller
{
    public function afficher(): View
    {
        return view('auth.connexion');
    }

    public function connecter(ConnexionRequest $request): RedirectResponse
    {
        $request->authentifier();

        // Nouvel identifiant de session après connexion : empêche la "fixation de session".
        $request->session()->regenerate();

        return redirect()->intended(route($request->user()->routeTableauDeBord()));
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('accueil');
    }
}
