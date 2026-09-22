<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\Ecouteur;
use App\Services\UtilisateurService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /admin/prestataires : valider les nouveaux profils, suspendre un prestataire. */
class PrestataireController extends Controller
{
    public function __construct(private readonly UtilisateurService $comptes, private readonly Ecouteur $notifications)
    {
    }

    public function index(): View
    {
        $prestataires = fn () => User::query()->where('est_prestataire', true)->where('est_admin', false)->with(['quartier', 'avatar']);

        return view('admin.prestataires', [
            'aValider' => $prestataires()->where('est_valide', false)->oldest()->paginate(10, pageName: 'attente')->withQueryString(),
            'valides' => $prestataires()->where('est_valide', true)->withCount('prestations')->latest('id')->paginate(15, pageName: 'valides')->withQueryString(),
        ]);
    }

    public function valider(Request $request, User $utilisateur): RedirectResponse
    {
        $erreur = $this->comptes->valider($request->user(), $utilisateur);

        if ($erreur === null) {
            $this->notifications->prestataireValide($utilisateur);
        }

        return back()->with($erreur ? 'erreur' : 'succes', $erreur ?? $utilisateur->nom_complet.' est validé : le prestataire peut maintenant se connecter et publier ses prestations.');
    }

    public function suspendre(Request $request, User $utilisateur): RedirectResponse
    {
        $erreur = $this->comptes->suspendre($request->user(), $utilisateur);

        if ($erreur === null) {
            $this->notifications->prestataireSuspendu($utilisateur);
        }

        return back()->with($erreur ? 'erreur' : 'succes', $erreur ?? $utilisateur->nom_complet.' est suspendu : il n\'a plus accès à la plateforme et ses offres ne sont plus au catalogue. « Valider » le rétablit.');
    }
}
