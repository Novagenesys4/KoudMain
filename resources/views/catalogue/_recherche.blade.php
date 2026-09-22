@use('App\Support\Format')
@use('App\Support\RechercheCriteres')
@php
    $servicesParId = $categories->flatMap->services->keyBy('id');
    $quartiersParId = $villes->flatMap->quartiers->keyBy('id');

    // Puces des filtres actifs : chacune est un lien qui retire CE filtre (et garde les autres).
    $puces = [];
    $retirer = fn (array $noms) => route($nomRoute, $criteres->versQuery($noms));

    if ($criteres->q !== '') {
        $puces[] = ['« '.$criteres->q.' »', $retirer(['q'])];
    }
    if ($criteres->categorie > 0 && $categories->firstWhere('id', $criteres->categorie)) {
        $puces[] = [$categories->firstWhere('id', $criteres->categorie)->nom, $retirer(['categorie'])];
    }
    if ($criteres->service > 0 && $servicesParId->has($criteres->service)) {
        $puces[] = [$servicesParId[$criteres->service]->nom, $retirer(['service'])];
    }
    if ($criteres->zone !== '') {
        [$typeZone, $idZone] = explode(':', $criteres->zone);
        $nomZone = $typeZone === 'v' ? $villes->firstWhere('id', (int) $idZone)?->nom : $quartiersParId->get((int) $idZone)?->nom;
        if ($nomZone) {
            $puces[] = [$nomZone, $retirer(['zone'])];
        }
    }
    if ($criteres->prixMin !== null || $criteres->prixMax !== null) {
        $libellePrix = match (true) {
            $criteres->prixMin !== null && $criteres->prixMax !== null => Format::montant($criteres->prixMin).' à '.Format::fcfa($criteres->prixMax),
            $criteres->prixMin !== null => 'Dès '.Format::fcfa($criteres->prixMin),
            default => 'Jusqu\'à '.Format::fcfa($criteres->prixMax),
        };
        $puces[] = [$libellePrix, $retirer(['prix_min', 'prix_max'])];
    }
    if ($criteres->noteMin > 0) {
        $puces[] = [RechercheCriteres::NOTES_MIN[rtrim(rtrim(number_format($criteres->noteMin, 1, '.', ''), '0'), '.')] ?? 'Note minimale', $retirer(['note_min'])];
    }
    if ($criteres->avecPhoto) {
        $puces[] = ['Avec photo', $retirer(['photo'])];
    }

    // Une page filtrée, triée ou au-delà de la première ne doit pas être indexée par Google (contenu quasi identique).
    $filtree = $puces !== [] || $criteres->tri !== 'recent' || $page->currentPage() > 1;
@endphp
{{-- Recherche du catalogue (filtres + résultats), partagée par la page publique et l'espace client.
     Variables : $nomRoute (route de la page), $espace (true dans l'espace client : le menu latéral réduit la place). --}}
<form method="GET" action="{{ route($nomRoute) }}" role="search" data-get-propre aria-label="Rechercher une prestation" class="{{ $espace ? '' : 'mt-8' }} grid items-start gap-8 {{ $espace ? 'xl:grid-cols-[16.5rem_minmax(0,1fr)] xl:gap-10' : 'lg:grid-cols-[16.5rem_minmax(0,1fr)] lg:gap-10' }}">
    {{-- ------------------------------------------------------------ Filtres --}}
    {{-- Toujours rendu « ouvert » (visible sans JavaScript) : le script le replie sur téléphone tant qu'aucun filtre n'est actif. --}}
    <details data-filtres data-filtres-seuil="{{ $espace ? 1280 : 1024 }}" open @if ($puces !== []) data-actif @endif class="group rounded-2xl border border-line bg-surface {{ $espace ? 'xl:sticky xl:top-24 xl:border-0 xl:bg-transparent' : 'lg:sticky lg:top-24 lg:border-0 lg:bg-transparent' }}">
        <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3.5 text-sm font-medium {{ $espace ? 'xl:hidden' : 'lg:hidden' }}">
            <span>Filtres @if ($criteres->nombreFiltres() > 0)<span class="ml-1.5 rounded-full bg-amber-tint px-2 py-0.5 text-xs text-ink">{{ $criteres->nombreFiltres() }}</span>@endif</span>
            <svg class="size-4 text-soft transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>

        <div class="grid gap-5 px-4 pb-5 pt-1 {{ $espace ? 'xl:p-0' : 'lg:p-0' }}">
            <div class="champ">
                <label for="f-categorie">Catégorie</label>
                <div class="champ-saisie">
                    <select id="f-categorie" name="categorie">
                        <option value="">Toutes les catégories</option>
                        @foreach ($categories as $categorie)
                            <option value="{{ $categorie->id }}" @selected($criteres->categorie === $categorie->id)>{{ $categorie->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="champ">
                <label for="f-service">Service</label>
                <div class="champ-saisie">
                    <select id="f-service" name="service">
                        <option value="">Tous les services</option>
                        @foreach ($categories as $categorie)
                            @if ($categorie->services->isNotEmpty())
                                <optgroup label="{{ $categorie->nom }}">
                                    @foreach ($categorie->services as $service)
                                        <option value="{{ $service->id }}" @selected($criteres->service === $service->id)>{{ $service->nom }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="champ">
                <label for="f-zone">Zone du prestataire</label>
                <div class="champ-saisie">
                    <select id="f-zone" name="zone">
                        <option value="">Toutes les zones</option>
                        @foreach ($villes as $ville)
                            @if ($ville->quartiers->isNotEmpty())
                                <optgroup label="{{ $ville->nom }}">
                                    <option value="v:{{ $ville->id }}" @selected($criteres->zone === 'v:'.$ville->id)>Toute la ville</option>
                                    @foreach ($ville->quartiers as $quartier)
                                        <option value="q:{{ $quartier->id }}" @selected($criteres->zone === 'q:'.$quartier->id)>{{ $quartier->nom }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>

            <fieldset class="champ">
                <legend class="champ-legende mb-1.5">Prix ({{ config('koudmain.devise') }})</legend>
                <div class="grid grid-cols-2 gap-2.5">
                    <div class="champ-saisie"><input type="text" name="prix_min" value="{{ $criteres->prixMin }}" inputmode="numeric" placeholder="Min" aria-label="Prix minimum" autocomplete="off"></div>
                    <div class="champ-saisie"><input type="text" name="prix_max" value="{{ $criteres->prixMax }}" inputmode="numeric" placeholder="Max" aria-label="Prix maximum" autocomplete="off"></div>
                </div>
            </fieldset>

            <div class="champ">
                <label for="f-note">Note</label>
                <div class="champ-saisie">
                    <select id="f-note" name="note_min">
                        <option value="">Toutes les notes</option>
                        @foreach (RechercheCriteres::NOTES_MIN as $valeur => $libelle)
                            <option value="{{ $valeur }}" @selected((float) $valeur === $criteres->noteMin)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="flex cursor-pointer items-center gap-3 text-sm">
                <input type="checkbox" name="photo" value="1" class="size-[1.1rem] cursor-pointer accent-amber" @checked($criteres->avecPhoto)>
                Uniquement avec photo
            </label>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-3 pt-1">
                <button type="submit" class="btn btn-plein min-h-11 px-5 text-sm" data-chargement="Un instant…"><span data-libelle>Appliquer</span></button>
                @if ($puces !== [])
                    <a href="{{ route($nomRoute) }}" class="lien text-sm">Tout réinitialiser</a>
                @endif
            </div>
        </div>
    </details>

    {{-- ------------------------------------------------------------ Résultats --}}
    <div class="min-w-0">
        <div class="flex h-14 items-center gap-2 rounded-2xl border border-line bg-surface pl-4 pr-1.5 shadow-soft transition-[box-shadow,border-color] duration-300 focus-within:border-accent focus-within:shadow-[0_0_0_3px_color-mix(in_srgb,var(--amber)_24%,transparent)]">
            <svg class="size-[1.15rem] shrink-0 text-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $criteres->q }}" maxlength="100" autocomplete="off" spellcheck="false" aria-label="Rechercher une prestation, un service ou un prestataire"
                   placeholder="Coiffure, plomberie, pressing…" class="h-11 min-w-0 flex-1 border-0 bg-transparent text-base text-ink outline-none placeholder:text-faint">
            <button type="submit" class="btn btn-ambre h-11 min-h-11 px-4 text-sm sm:px-5" data-chargement="Recherche…"><span data-libelle>Rechercher</span></button>
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
            <p class="text-sm text-soft" role="status">
                <strong class="font-semibold text-ink">{{ Format::pluriel($page->total(), 'prestation') }}</strong>
                @if ($criteres->q !== '') pour « {{ $criteres->q }} » @endif
            </p>

            <div class="flex items-center gap-2.5 text-sm">
                <label for="f-tri" class="text-soft">Trier par</label>
                <div class="champ-saisie w-48">
                    <select id="f-tri" name="tri" data-auto-submit class="!min-h-10 !py-1.5 !text-sm">
                        @foreach (RechercheCriteres::TRIS as $valeur => $libelle)
                            @if ($valeur !== 'pertinence' || $criteres->q !== '')
                                <option value="{{ $valeur }}" @selected($criteres->tri === $valeur)>{{ $libelle }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        @if ($puces !== [])
            <ul class="mt-4 flex flex-wrap gap-2" aria-label="Filtres actifs">
                @foreach ($puces as [$libelle, $lien])
                    <li>
                        <a href="{{ $lien }}" class="puce group/puce transition-colors hover:border-accent hover:text-ink" title="Retirer ce filtre">
                            {{ $libelle }}
                            <svg class="size-3 text-faint transition-colors group-hover/puce:text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            <span class="sr-only">Retirer ce filtre</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($cartes === [])
            <div class="mt-8 rounded-2xl border border-dashed border-line px-6 py-14 text-center">
                @if ($puces === [])
                    <h2 class="text-xl font-semibold">Le catalogue est vide pour le moment</h2>
                    <p class="mx-auto mt-3 max-w-md text-soft">Les premières prestations arrivent bientôt. Vous êtes prestataire ? Créez votre compte et proposez vos services.</p>
                    @guest
                        <a href="{{ route('inscription') }}" class="btn btn-plein mt-6"><span>Proposer mes services</span></a>
                    @endguest
                @else
                    <h2 class="text-xl font-semibold">Aucune prestation ne correspond</h2>
                    <p class="mx-auto mt-3 max-w-md text-soft">Essayez avec moins de filtres, une autre orthographe ou un mot plus court (« plomb » trouve « plomberie »).</p>
                    <a href="{{ route($nomRoute) }}" class="btn mt-6"><span>Voir toutes les prestations</span></a>
                @endif
            </div>
        @else
            {{-- Île React : cartes animées. Le repli (même contenu, sans animation) sert au référencement et aux visiteurs sans JavaScript. --}}
            <x-island nom="Catalogue" class="mt-6" :donnees="['prestations' => $cartes, 'espace' => $espace]">
                <ul class="grid gap-4 {{ $espace ? 'sm:grid-cols-2 2xl:grid-cols-3' : 'sm:grid-cols-2 xl:grid-cols-3' }}">
                    @foreach ($cartes as $carte)
                        <li><x-prestation-repli :carte="$carte" /></li>
                    @endforeach
                </ul>
            </x-island>

            {{ $page->onEachSide(1)->links('pagination.koudmain') }}
        @endif
    </div>
</form>
