<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** /admin/catalogue : les catégories et les services proposés aux prestataires. */
class CatalogueController extends Controller
{
    public function index(): View
    {
        return view('admin.catalogue', [
            'categories' => Categorie::query()
                ->with(['services' => fn ($q) => $q->withCount('prestations')->orderBy('nom')])
                ->orderBy('nom')
                ->get(),
        ]);
    }

    public function creerCategorie(Request $request): RedirectResponse
    {
        $donnees = $request->validateWithBag('categorie', [
            'nom' => ['required', 'string', 'max:100', Rule::unique('categories', 'nom')],
        ], [
            'nom.required' => 'Indiquez le nom de la catégorie.',
            'nom.max' => 'Le nom est trop long (100 caractères au maximum).',
            'nom.unique' => 'Cette catégorie existe déjà.',
        ]);

        $categorie = Categorie::query()->create(['nom' => trim($donnees['nom'])]);
        Log::info('admin.categorie_creee', ['admin' => $request->user()->id, 'categorie' => $categorie->id]);

        return back()->with('succes', 'Catégorie « '.$categorie->nom.' » ajoutée. Ajoutez-y maintenant des services.');
    }

    public function supprimerCategorie(Request $request, Categorie $categorie): RedirectResponse
    {
        if ($categorie->services()->exists()) {
            return back()->with('erreur', 'Cette catégorie contient encore des services : supprimez-les d\'abord.');
        }

        $categorie->delete();
        Log::info('admin.categorie_supprimee', ['admin' => $request->user()->id, 'categorie' => $categorie->id]);

        return back()->with('succes', 'Catégorie « '.$categorie->nom.' » supprimée.');
    }

    public function creerService(Request $request): RedirectResponse
    {
        $donnees = $request->validateWithBag('service', [
            'categorie_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'nom' => ['required', 'string', 'max:100'],
        ], [
            'categorie_id.required' => 'Choisissez une catégorie.',
            'categorie_id.exists' => 'Cette catégorie n\'existe pas.',
            'nom.required' => 'Indiquez le nom du service.',
            'nom.max' => 'Le nom est trop long (100 caractères au maximum).',
        ]);

        $nom = trim($donnees['nom']);

        if (Service::query()->where('categorie_id', $donnees['categorie_id'])->whereRaw('lower(nom) = ?', [mb_strtolower($nom)])->exists()) {
            return back()->withInput()->withErrors(['nom' => 'Ce service existe déjà dans cette catégorie.'], 'service');
        }

        $service = Categorie::query()->findOrFail($donnees['categorie_id'])->services()->create(['nom' => $nom]);
        Log::info('admin.service_cree', ['admin' => $request->user()->id, 'service' => $service->id]);

        return back()->with('succes', 'Service « '.$service->nom.' » ajouté : les prestataires peuvent le choisir.');
    }

    public function supprimerService(Request $request, Service $service): RedirectResponse
    {
        if ($service->prestations()->exists()) {
            return back()->with('erreur', 'Des prestations utilisent ce service : il ne peut pas être supprimé.');
        }

        try {
            $service->delete();
        } catch (QueryException) {
            return back()->with('erreur', 'Ce service est encore utilisé : il ne peut pas être supprimé.');
        }

        Log::info('admin.service_supprime', ['admin' => $request->user()->id, 'service' => $service->id]);

        return back()->with('succes', 'Service « '.$service->nom.' » supprimé.');
    }
}
