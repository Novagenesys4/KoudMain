@use('App\Support\Format')
<x-layouts.espace titre="Mes prestations">
    <x-espace.entete etiquette="Espace prestataire" titre="Mes" suite="prestations"
                     intro="Ce que vous proposez aux clients. Masquez une offre pour la retirer du catalogue sans rien perdre.">
        <a href="{{ route('prestataire.prestations.creer') }}" class="btn btn-plein"><x-icone nom="plus" taille="size-4" /><span>Nouvelle prestation</span></a>
    </x-espace.entete>

    @if ($total === 0)
        <x-espace.panneau titre="Vos prestations">
            <x-espace.vide icone="mallette" titre="Vous n'avez pas encore de prestation"
                           texte="Décrivez ce que vous proposez, fixez votre prix et ajoutez quelques photos : votre prestation apparaît aussitôt dans le catalogue.">
                <a href="{{ route('prestataire.prestations.creer') }}" class="btn btn-plein"><span>Publier ma première prestation</span></a>
            </x-espace.vide>
        </x-espace.panneau>
    @else
        @if ($q !== '')
            <p class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-soft" role="status">
                <span>{{ Format::pluriel($prestations->total(), 'résultat') }} pour « {{ $q }} »</span>
                <a href="{{ route('prestataire.prestations.index') }}" class="lien">Effacer la recherche</a>
            </p>
        @endif

        @if ($prestations->isEmpty())
            <x-espace.panneau titre="Résultats">
                <x-espace.vide icone="recherche" titre="Aucune prestation ne correspond" texte="Essayez avec un autre mot : le titre ou le nom du service.">
                    <a href="{{ route('prestataire.prestations.index') }}" class="btn btn-petit"><span>Tout afficher</span></a>
                </x-espace.vide>
            </x-espace.panneau>
        @else
            <ul role="list" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($prestations as $prestation)
                    @php($photo = $prestation->medias->first())
                    <li class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-surface" data-reveal style="--i: {{ $loop->index % 3 }}">
                        <a href="{{ route('prestataire.prestations.modifier', $prestation) }}" class="group relative block aspect-[16/10] overflow-hidden bg-deep" tabindex="-1" aria-hidden="true">
                            @if ($photo)
                                <img src="{{ $photo->url() }}" alt="" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                            @else
                                <span class="grid size-full place-items-center text-faint"><x-icone nom="image" taille="size-7" /></span>
                            @endif
                        </a>

                        <div class="flex flex-1 flex-col p-5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="puce">{{ $prestation->service->categorie->nom }}</span>
                                <span class="puce">{{ $prestation->service->nom }}</span>
                                @if ($prestation->est_active)
                                    <span class="statut statut-ok">Publiée</span>
                                @else
                                    <span class="statut statut-attente">Masquée</span>
                                @endif
                            </div>

                            <h2 class="mt-3 text-lg leading-snug"><a href="{{ route('prestations.voir', $prestation) }}" class="lien">{{ $prestation->titre }}</a></h2>
                            @if (filled($prestation->description))
                                <p class="mt-1.5 line-clamp-2 text-sm text-soft">{{ $prestation->description }}</p>
                            @endif

                            <div class="mt-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 pt-1">
                                <p class="text-xl font-semibold tracking-tight tabular-nums">{{ Format::montant($prestation->prix) }} <span class="text-xs font-medium text-soft">{{ config('koudmain.devise') }}</span></p>
                                <p class="flex items-center gap-1 text-sm text-soft">
                                    <x-icone nom="etoile" taille="size-3.5" class="text-accent" />
                                    @if ($prestation->avis_count > 0)
                                        <span class="font-medium text-ink">{{ Format::note((float) $prestation->avis_avg_note) }}</span> ({{ $prestation->avis_count }})
                                    @else
                                        Pas d'avis
                                    @endif
                                    <span aria-hidden="true">·</span> {{ Format::pluriel($prestation->medias->count(), 'photo') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3.5">
                            <a href="{{ route('prestataire.prestations.modifier', $prestation) }}" class="btn btn-petit"><x-icone nom="crayon" taille="size-3.5" /><span>Modifier</span></a>
                            <form method="POST" action="{{ route('prestataire.prestations.activation', $prestation) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-petit"><x-icone :nom="$prestation->est_active ? 'oeil-barre' : 'oeil'" taille="size-3.5" /><span>{{ $prestation->est_active ? 'Masquer' : 'Publier' }}</span></button>
                            </form>
                            <form method="POST" action="{{ route('prestataire.prestations.supprimer', $prestation) }}" class="ml-auto"
                                  data-confirmer="Supprimer définitivement « {{ $prestation->titre }} » ? La prestation et ses photos seront effacées, sans retour possible." data-confirmer-bouton="Oui, supprimer">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-petit btn-danger" aria-label="Supprimer {{ $prestation->titre }}"><x-icone nom="corbeille" taille="size-3.5" /><span class="sr-only">Supprimer</span></button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $prestations->links('pagination.espace') }}</div>
        @endif
    @endif
</x-layouts.espace>
