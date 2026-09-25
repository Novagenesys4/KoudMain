<x-layouts.espace titre="Disponibilités" :recherche="false">
    <x-espace.entete etiquette="Espace prestataire" titre="Mes" suite="disponibilités"
                     intro="Indiquez quand vous travaillez. Les clients ne peuvent réserver que dans ces plages, et vos commandes acceptées bloquent leur créneau automatiquement.">
        <a href="{{ route('prestataire.commandes') }}" class="btn btn-petit"><x-icone nom="liste" taille="size-4" /><span>Voir mes commandes</span></a>
    </x-espace.entete>

    @unless ($defini)
        <p class="message mb-6" role="status">Vous n'avez pas encore indiqué d'horaires : les clients peuvent réserver tous les jours entre 07 h et 21 h. Renseignez vos plages pour éviter les demandes à des heures où vous ne travaillez pas.</p>
    @endunless

    @if ($errors->has('horaires'))
        <div class="message message-erreur mb-6" role="alert">
            <p class="font-medium">Certaines plages ne sont pas valides :</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->get('horaires') as $erreur)<li>{{ $erreur }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('prestataire.disponibilites.enregistrer') }}">
        @csrf
        @method('PUT')
        <x-espace.panneau titre="Ma semaine type" etiquette="Trois plages par jour au maximum" data-reveal>
            <ul role="list" class="divide-y divide-line">
                @foreach ($jours as $numero => $nom)
                    @php($plages = old("jours.$numero") ? collect(old("jours.$numero"))->map(fn ($p) => [$p['debut'] ?? '', $p['fin'] ?? ''])->values()->all() : ($horaires[$numero] ?? []))
                    <li class="grid items-center gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-[8rem_1fr]">
                        <p class="font-medium">{{ $nom }}</p>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @for ($i = 0; $i < $plagesParJour; $i++)
                                <fieldset class="flex items-center gap-2">
                                    <legend class="sr-only">{{ $nom }}, plage {{ $i + 1 }}</legend>
                                    <div class="champ-saisie w-full">
                                        <select name="jours[{{ $numero }}][{{ $i }}][debut]" aria-label="{{ $nom }} : début de la plage {{ $i + 1 }}">
                                            <option value="">—</option>
                                            @foreach ($heures as $h)
                                                <option value="{{ $h }}" @selected(($plages[$i][0] ?? '') === $h)>{{ str_replace(':', ' h ', $h) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <span class="text-faint" aria-hidden="true">à</span>
                                    <div class="champ-saisie w-full">
                                        <select name="jours[{{ $numero }}][{{ $i }}][fin]" aria-label="{{ $nom }} : fin de la plage {{ $i + 1 }}">
                                            <option value="">—</option>
                                            @foreach ($heures as $h)
                                                <option value="{{ $h }}" @selected(($plages[$i][1] ?? '') === $h)>{{ str_replace(':', ' h ', $h) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </fieldset>
                            @endfor
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="flex flex-wrap items-center gap-4 border-t border-line px-5 py-4">
                <button type="submit" class="btn btn-plein" data-chargement="Enregistrement…"><span data-libelle>Enregistrer mes horaires</span></button>
                <p class="text-sm text-faint">Laissez les heures vides pour un jour de repos. Sans aucune plage, tous les jours (07 h – 21 h) sont ouverts.</p>
            </div>
        </x-espace.panneau>
    </form>
</x-layouts.espace>
