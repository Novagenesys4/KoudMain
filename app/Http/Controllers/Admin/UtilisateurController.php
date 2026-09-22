<?php

namespace App\Http\Controllers\Admin;

use App\Support\Saisie;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UtilisateurService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /admin/utilisateurs : tous les comptes (recherche, filtre par rôle) et suppression. */
class UtilisateurController extends Controller
{
    private const ROLES = ['client', 'prestataire', 'admin'];

    public function __construct(private readonly UtilisateurService $comptes)
    {
    }

    public function index(Request $request): View
    {
        $q = Saisie::texte($request->query('q'));
        $role = in_array($request->query('role'), self::ROLES, true) ? (string) $request->query('role') : '';

        $requete = User::query()->with(['quartier', 'avatar'])->latest('id');

        if ($q !== '') {
            $fragment = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
            $requete->where(function ($sous) use ($fragment): void {
                $sous->whereRaw("immutable_unaccent(lower(prenom || ' ' || nom)) LIKE immutable_unaccent(?)", [$fragment])
                    ->orWhereRaw('lower(email) LIKE ?', [$fragment])
                    ->orWhere('telephone', 'like', $fragment);
            });
        }

        match ($role) {
            'client' => $requete->where('est_client', true),
            'prestataire' => $requete->where('est_prestataire', true),
            'admin' => $requete->where('est_admin', true),
            default => null,
        };

        return view('admin.utilisateurs', [
            'utilisateurs' => $requete->paginate(15)->withQueryString(),
            'q' => $q,
            'role' => $role,
        ]);
    }

    public function destroy(Request $request, User $utilisateur): RedirectResponse
    {
        $erreur = $this->comptes->supprimer($request->user(), $utilisateur);

        return back()->with($erreur ? 'erreur' : 'succes', $erreur ?? 'Le compte de '.$utilisateur->nom_complet.' est supprimé.');
    }
}
