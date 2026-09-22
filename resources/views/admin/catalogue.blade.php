@use('App\Support\Format')
@php
    $errCat = $errors->getBag('categorie');
    $errSrv = $errors->getBag('service');
@endphp
<x-layouts.espace titre="Catalogue" :recherche="false">
    <x-espace.entete etiquette="Administration" titre="Le" suite="catalogue"
                     intro="Les catégories et les services que les prestataires peuvent proposer. Un service utilisé par une prestation ne peut pas être supprimé." />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <div class="grid gap-6">
            @forelse ($categories as $categorie)
                <x-espace.panneau :titre="$categorie->nom" :etiquette="Format::pluriel($categorie->services->count(), 'service')" data-reveal>
                    <x-slot:actions>
                        @if ($categorie->services->isEmpty())
                            <form method="POST" action="{{ route('admin.categories.supprimer', $categorie->id) }}"
                                  data-confirmer="Supprimer la catégorie « {{ $categorie->nom }} » ?" data-confirmer-bouton="Oui, supprimer">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-petit btn-danger"><x-icone nom="corbeille" taille="size-3.5" /><span>Supprimer la catégorie</span></button>
                            </form>
                        @endif
                    </x-slot:actions>

                    @if ($categorie->services->isEmpty())
                        <p class="px-5 py-4 text-sm text-soft">Cette catégorie n'a pas encore de service.</p>
                    @else
                        <ul role="list" class="divide-y divide-line">
                            @foreach ($categorie->services as $service)
                                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
                                    <span class="min-w-0"><span class="font-medium">{{ $service->nom }}</span>
                                        <span class="ml-2 text-sm text-faint">{{ $service->prestations_count > 0 ? Format::pluriel($service->prestations_count, 'prestation') : 'Aucune prestation' }}</span></span>
                                    @if ($service->prestations_count === 0)
                                        <form method="POST" action="{{ route('admin.services.supprimer', $service->id) }}"
                                              data-confirmer="Supprimer le service « {{ $service->nom }} » ?" data-confirmer-bouton="Oui, supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="lien cursor-pointer text-sm text-danger" aria-label="Supprimer le service {{ $service->nom }}">Supprimer</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-espace.panneau>
            @empty
                <x-espace.panneau titre="Catégories">
                    <x-espace.vide icone="calques" titre="Aucune catégorie" texte="Ajoutez une première catégorie, puis les services qu'elle contient." />
                </x-espace.panneau>
            @endforelse
        </div>

        <div class="grid gap-6 xl:sticky xl:top-[calc(var(--espace-haut)+1.5rem)]">
            <x-espace.panneau titre="Nouvelle catégorie" data-reveal>
                <form method="POST" action="{{ route('admin.categories.creer') }}" novalidate class="grid gap-4 p-5">
                    @csrf
                    <div class="champ">
                        <label for="cat-nom">Nom</label>
                        <div class="champ-saisie">
                            <input id="cat-nom" name="nom" type="text" required maxlength="100" value="{{ $errCat->any() ? old('nom') : '' }}" placeholder="Ex. : Beauté"
                                   @if ($errCat->has('nom')) aria-invalid="true" aria-describedby="e-cat-nom" @endif>
                        </div>
                        @if ($errCat->has('nom'))<p id="e-cat-nom" class="champ-erreur">{{ $errCat->first('nom') }}</p>@endif
                    </div>
                    <div><x-bouton chargement="Ajout…">Ajouter la catégorie</x-bouton></div>
                </form>
            </x-espace.panneau>

            <x-espace.panneau titre="Nouveau service" data-reveal style="--i: 1">
                @if ($categories->isEmpty())
                    <p class="p-5 text-sm text-soft">Créez d'abord une catégorie.</p>
                @else
                    <form method="POST" action="{{ route('admin.services.creer') }}" novalidate class="grid gap-4 p-5">
                        @csrf
                        <div class="champ">
                            <label for="srv-categorie">Catégorie</label>
                            <div class="champ-saisie">
                                <select id="srv-categorie" name="categorie_id" required @if ($errSrv->has('categorie_id')) aria-invalid="true" aria-describedby="e-srv-categorie" @endif>
                                    <option value="">Choisissez une catégorie</option>
                                    @foreach ($categories as $categorie)
                                        <option value="{{ $categorie->id }}" @selected((int) ($errSrv->any() ? old('categorie_id') : 0) === $categorie->id)>{{ $categorie->nom }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if ($errSrv->has('categorie_id'))<p id="e-srv-categorie" class="champ-erreur">{{ $errSrv->first('categorie_id') }}</p>@endif
                        </div>
                        <div class="champ">
                            <label for="srv-nom">Nom du service</label>
                            <div class="champ-saisie">
                                <input id="srv-nom" name="nom" type="text" required maxlength="100" value="{{ $errSrv->any() ? old('nom') : '' }}" placeholder="Ex. : Tresses"
                                       @if ($errSrv->has('nom')) aria-invalid="true" aria-describedby="e-srv-nom" @endif>
                            </div>
                            @if ($errSrv->has('nom'))<p id="e-srv-nom" class="champ-erreur">{{ $errSrv->first('nom') }}</p>@endif
                        </div>
                        <div><x-bouton chargement="Ajout…">Ajouter le service</x-bouton></div>
                    </form>
                @endif
            </x-espace.panneau>
        </div>
    </div>
</x-layouts.espace>
