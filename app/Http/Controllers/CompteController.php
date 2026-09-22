<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangerMotDePasseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CompteController extends Controller
{
    public function motDePasse(): View
    {
        return view('compte.mot-de-passe');
    }

    public function changerMotDePasse(ChangerMotDePasseRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Le cast "hashed" du modèle User hache le mot de passe à l'affectation.
        $nouveau = $request->validated('password');
        $user->forceFill(['password' => $nouveau])->save();

        // Règle 9 : changer de mot de passe DÉCONNECTE tous les autres appareils (et invalide leur cookie « rester connecté »).
        // Le middleware AuthenticateSession compare, à chaque requête, l'empreinte du mot de passe gardée dans la session à celle de la base.
        Auth::logoutOtherDevices($nouveau);

        // Nouvel identifiant de session : une éventuelle session volée n'est plus utilisable telle quelle.
        $request->session()->regenerate();

        Log::info('compte.mot_de_passe_change', ['utilisateur' => $user->id]);

        return redirect()->route('compte.mot-de-passe')->with('succes', 'Votre mot de passe a été modifié.');
    }
}
