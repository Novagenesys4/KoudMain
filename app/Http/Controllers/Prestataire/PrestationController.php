<?php

namespace App\Http\Controllers\Prestataire;

use App\Support\Saisie;
use App\Http\Controllers\Controller;
use App\Http\Requests\Prestation\PrestationRequest;
use App\Models\Prestation;
use App\Services\Media\ImageInvalide;
use App\Services\PrestationService;
use App\Support\Referentiel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** L'espace prestataire : gérer ses prestations (l'accès est limité aux prestataires validés par les routes). */
class PrestationController extends Controller
{
    public function __construct(private readonly PrestationService $prestations)
    {
    }

    /** Mes prestations : recherche par titre ou service (sans tenir compte des accents), 9 par page. */
    public function index(Request $request): View
    {
        $q = Saisie::texte($request->query('q'));

        $requete = $request->user()->prestations()
            ->with(['service.categorie', 'medias'])
            ->withCount('avis')
            ->withAvg('avis', 'note')
            ->latest('prestations.id');

        if ($q !== '') {
            // Les jokers LIKE saisis par l'utilisateur (% et _) sont neutralisés : on cherche le texte tel quel.
            $fragment = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';

            $requete->where(function ($sous) use ($fragment): void {
                $sous->whereRaw('immutable_unaccent(lower(prestations.titre)) LIKE immutable_unaccent(?)', [$fragment])
                    ->orWhereHas('service', fn ($s) => $s->whereRaw('immutable_unaccent(lower(services.nom)) LIKE immutable_unaccent(?)', [$fragment]));
            });
        }

        return view('prestataire.prestations.index', [
            'prestations' => $requete->paginate(9)->withQueryString(),
            'q' => $q,
            'total' => $request->user()->prestations()->count(),
        ]);
    }

    public function create(): View
    {
        return view('prestataire.prestations.formulaire', [
            'prestation' => new Prestation(['est_active' => true]),
            'categories' => $this->categories(),
        ]);
    }

    public function store(PrestationRequest $request): RedirectResponse
    {
        try {
            $prestation = $this->prestations->creer($request->user(), $request->donneesPrestation(), $request->photos());
        } catch (ImageInvalide $e) {
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
        }

        return redirect()->route('prestataire.prestations.modifier', $prestation)
            ->with('succes', 'Prestation publiée. Elle est visible dans le catalogue. Ajoutez des photos pour donner confiance aux clients.');
    }

    public function edit(Prestation $prestation): View
    {
        Gate::authorize('gerer', $prestation);

        $prestation->load('medias');

        return view('prestataire.prestations.formulaire', [
            'prestation' => $prestation,
            'categories' => $this->categories(),
            'placesRestantes' => $this->prestations->placesRestantes($prestation),
        ]);
    }

    public function update(PrestationRequest $request, Prestation $prestation): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);

        try {
            $this->prestations->mettreAJour($prestation, $request->donneesPrestation(), $request->photos());
        } catch (ImageInvalide $e) {
            return back()->withInput()->withErrors(['photos' => $e->getMessage()]);
        }

        return redirect()->route('prestataire.prestations.modifier', $prestation)->with('succes', 'Prestation mise à jour.');
    }

    /** Masquer (retirer du catalogue sans rien perdre) ou publier de nouveau. */
    public function activation(Prestation $prestation): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);

        $this->prestations->basculerActivation($prestation);

        return back()->with('succes', $prestation->est_active
            ? 'Prestation publiée : elle apparaît de nouveau dans le catalogue.'
            : 'Prestation masquée : elle n\'apparaît plus dans le catalogue. Vous pouvez la publier de nouveau à tout moment.');
    }

    public function destroy(Prestation $prestation): RedirectResponse
    {
        Gate::authorize('gerer', $prestation);

        if (! $this->prestations->supprimer($prestation)) {
            return back()->with('erreur', 'Cette prestation a déjà été commandée : elle ne peut pas être supprimée, car l\'historique des commandes et les avis en dépendent. Masquez-la plutôt : elle disparaîtra du catalogue.');
        }

        return redirect()->route('prestataire.prestations.index')->with('succes', 'Prestation supprimée.');
    }

    private function categories()
    {
        return Referentiel::categoriesAvecServices();
    }
}
