@use('App\Support\Format')
@php
    $categorie = $prestation->service->categorie;
    $zone = $prestataire->quartier->nom.', '.$prestataire->quartier->ville->nom;
    $premiere = $photos[0]['url'] ?? null;
    $description = $prestation->description
        ? \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $prestation->description), 155)
        : $prestation->titre.' : '.$prestation->service->nom.' à '.$prestataire->quartier->nom.', par '.$prestataire->nom_complet.'. Prix, avis et réservation sur KoudMain.';

    // Données structurées (Google) : uniquement des faits réels. La note n'est déclarée que s'il y a des avis.
    $donneesStructurees = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Service',
        'name' => $prestation->titre,
        'description' => $description,
        'serviceType' => $prestation->service->nom,
        'url' => route('prestations.voir', $prestation),
        'image' => $premiere,
        'areaServed' => $prestataire->quartier->ville->nom,
        'provider' => ['@type' => 'Person', 'name' => $prestataire->nom_complet, 'url' => route('prestataires.voir', $prestataire)],
        'offers' => ['@type' => 'Offer', 'price' => (string) (int) round((float) $prestation->prix), 'priceCurrency' => 'XOF'],
        'aggregateRating' => $moyenne !== null ? ['@type' => 'AggregateRating', 'ratingValue' => $moyenne, 'reviewCount' => $nombreAvis] : null,
    ]);
@endphp
<x-layouts.app :titre="$prestation->titre" :description="$description" :image="$premiere"
               :canonique="route('prestations.voir', $prestation)" :robots="$visiblePourTous ? null : 'noindex,nofollow'">
    @if ($visiblePourTous)
        <script type="application/ld+json">{!! json_encode($donneesStructurees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @endif

    <section class="mx-auto w-full max-w-6xl px-5 pt-8 sm:px-8 lg:pt-10">
        <nav aria-label="Fil d'Ariane" class="text-sm text-soft">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <li><a href="{{ route('catalogue') }}" class="lien">Catalogue</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('catalogue', ['categorie' => $categorie->id]) }}" class="lien">{{ $categorie->nom }}</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('catalogue', ['service' => $prestation->service_id]) }}" class="lien">{{ $prestation->service->nom }}</a></li>
            </ol>
        </nav>

        @unless ($visiblePourTous)
            <p class="message mt-6" role="status">
                Cette prestation est <strong>masquée</strong> : vous seul(e) la voyez. Les clients n'y ont pas accès tant qu'elle n'est pas publiée.
                @if ($estProprietaire)
                    <a href="{{ route('prestataire.prestations.modifier', $prestation) }}" class="lien">La modifier</a>
                @endif
            </p>
        @endunless

        <div class="mt-6 grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_21rem] lg:gap-14">
            {{-- ------------------------------------------------------------ Contenu --}}
            <div class="min-w-0">
                @if ($photos !== [])
                    <x-island nom="PrestationGalerie" :donnees="['photos' => $photos, 'titre' => $prestation->titre]">
                        <img src="{{ $photos[0]['url'] }}" alt="{{ $prestation->titre }}" width="{{ $photos[0]['largeur'] }}" height="{{ $photos[0]['hauteur'] }}" class="aspect-[4/3] w-full rounded-3xl border border-line bg-deep object-cover">
                    </x-island>
                @else
                    <div class="grid aspect-[16/9] place-items-center rounded-3xl border border-dashed border-line bg-deep px-6 text-center text-sm text-soft">
                        <p>Ce prestataire n'a pas encore ajouté de photo.</p>
                    </div>
                @endif

                <div class="mt-8">
                    <p class="etiquette">{{ $prestation->service->nom }}</p>
                    <h1 class="mt-3 text-3xl sm:text-4xl">{{ $prestation->titre }}</h1>

                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                        <x-etoiles :note="$moyenne" :nombre="$nombreAvis" />
                        <span class="text-soft">{{ $zone }}</span>
                        @if ($duree)<span class="text-soft">Durée : {{ $duree }}</span>@endif
                    </div>
                </div>

                <div class="mt-10 border-t border-line pt-8">
                    <h2 class="text-xl font-semibold">Description</h2>
                    @if ($prestation->description)
                        <p class="mt-4 max-w-2xl whitespace-pre-line leading-relaxed">{{ $prestation->description }}</p>
                    @else
                        <p class="mt-4 text-soft">Le prestataire n'a pas encore rédigé de description.</p>
                    @endif
                </div>

                {{-- Disponibilités --}}
                @if ($visiblePourTous)
                    <div class="mt-10 border-t border-line pt-8">
                        <h2 class="text-xl font-semibold">Disponibilités</h2>
                        @if ($horaires === [])
                            <p class="mt-4 max-w-xl text-soft">{{ $prestataire->prenom }} n'a pas précisé ses horaires : les réservations sont possibles tous les jours entre 07 h et 21 h, et il confirme en acceptant la commande.</p>
                        @else
                            <dl class="mt-4 grid max-w-md gap-x-6 gap-y-2 text-sm sm:grid-cols-[7rem_1fr]">
                                @foreach ($jours as $numero => $nomJour)
                                    <dt class="text-soft">{{ $nomJour }}</dt>
                                    <dd class="tabular-nums">
                                        @if (isset($horaires[$numero]))
                                            {{ collect($horaires[$numero])->map(fn ($p) => $p[0].' – '.$p[1])->implode(' · ') }}
                                        @else
                                            <span class="text-faint">Fermé</span>
                                        @endif
                                    </dd>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endif

                {{-- Prestataire --}}
                <div class="mt-10 border-t border-line pt-8">
                    <h2 class="text-xl font-semibold">Votre prestataire</h2>
                    <div class="mt-5 flex items-start gap-4">
                        <x-avatar :utilisateur="$prestataire" taille="size-14 text-lg" />
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-x-2 font-semibold">
                                <a href="{{ route('prestataires.voir', $prestataire) }}" class="lien">{{ $prestataire->nom_complet }}</a>
                                @if ($prestataire->est_valide)<span class="puce">Prestataire vérifié</span>@endif
                            </p>
                            <p class="mt-1 text-sm text-soft">{{ $zone }}</p>
                            @if ($prestataire->bio)
                                <p class="mt-3 max-w-xl whitespace-pre-line text-sm leading-relaxed">{{ \Illuminate\Support\Str::limit($prestataire->bio, 280) }}</p>
                            @endif
                            <a href="{{ route('prestataires.voir', $prestataire) }}" class="lien mt-3 inline-block text-sm">Voir toutes ses prestations</a>
                        </div>
                    </div>
                </div>

                {{-- Avis --}}
                <div id="avis" class="mt-10 border-t border-line pt-8">
                    <h2 class="text-xl font-semibold">Avis des clients</h2>
                    @if ($avis->isEmpty())
                        <p class="mt-4 max-w-xl text-soft">Aucun avis pour le moment. Seuls les clients dont la commande est terminée peuvent en laisser un : les notes affichées sont toujours réelles.</p>
                    @else
                        <ul class="mt-5 grid gap-6">
                            @foreach ($avis as $unAvis)
                                <li class="max-w-2xl">
                                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <p class="font-medium">{{ $unAvis->client->prenom }} {{ mb_substr($unAvis->client->nom, 0, 1) }}.</p>
                                        <p class="text-sm text-soft">{{ $unAvis->created_at->translatedFormat('j F Y') }}</p>
                                    </div>
                                    <x-etoiles :note="(float) $unAvis->note" :nombre="1" seule class="mt-1 text-sm" />
                                    @if ($unAvis->commentaire)
                                        <p class="mt-2 whitespace-pre-line leading-relaxed">{{ $unAvis->commentaire }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------------------ Panneau de réservation --}}
            <aside class="lg:sticky lg:top-24" aria-label="Réserver cette prestation">
                <div class="rounded-3xl border border-line bg-surface p-6 shadow-soft">
                    <p class="text-sm text-soft">À partir de</p>
                    <p class="mt-1 text-4xl font-semibold tracking-tight">{{ Format::montant($prestation->prix) }} <span class="text-lg font-medium text-soft">{{ config('koudmain.devise') }}</span></p>
                    @if ($duree)<p class="mt-1 text-sm text-soft">Durée estimée : {{ $duree }}</p>@endif

                    <div class="mt-6">
                        @if ($estProprietaire)
                            <a href="{{ route('prestataire.prestations.modifier', $prestation) }}" class="btn btn-plein w-full"><span>Modifier ma prestation</span></a>
                        @elseif (! $visiblePourTous)
                            {{-- administrateur : aperçu seulement --}}
                        @elseif (! auth()->check())
                            <a href="{{ route('connexion') }}" class="btn btn-plein w-full"><span>Se connecter pour commander</span></a>
                            <p class="mt-3 text-center text-sm text-soft">Pas encore de compte ? <a href="{{ route('inscription') }}" class="lien">Créer un compte</a></p>
                        @elseif (auth()->user()->aLeRole('client'))
                            <a href="{{ route('client.commander', $prestation) }}" class="btn btn-plein w-full"><span>Commander</span><x-icone nom="fleche-droite" taille="size-4" /></a>
                            @if ($prochainCreneau)
                                <p class="mt-3 text-center text-sm text-soft">Prochain créneau libre : <strong class="font-medium text-ink">{{ $prochainCreneau['jour'] }} à {{ $prochainCreneau['heure'] }}</strong></p>
                            @else
                                <p class="mt-3 text-center text-sm text-soft">Aucun créneau libre dans les {{ config('koudmain.reservation.jours') }} prochains jours.</p>
                            @endif
                            @if ($favori !== null)
                                {{-- Sans JavaScript aussi : un simple formulaire qui revient sur cette page. --}}
                                <form method="POST" action="{{ route('client.favoris.basculer', $prestation) }}" class="mt-3">
                                    @csrf
                                    <button type="submit" class="btn w-full" aria-pressed="{{ $favori ? 'true' : 'false' }}">
                                        <x-icone nom="coeur" taille="size-4" :class="$favori ? 'fill-rose text-rose' : ''" />
                                        <span>{{ $favori ? 'Retirer des favoris' : 'Ajouter aux favoris' }}</span>
                                    </button>
                                </form>
                            @endif
                        @else
                            <p class="text-center text-sm text-soft">Seul un compte client peut commander.</p>
                        @endif
                    </div>

                    <ul class="mt-6 grid gap-3 border-t border-line pt-5 text-sm">
                        <li class="flex gap-3"><span class="text-accent" aria-hidden="true">●</span><span>Payez en main propre, ou par Mobile Money / carte (bloqué en séquestre jusqu'à la fin de la prestation).</span></li>
                        <li class="flex gap-3"><span class="text-accent" aria-hidden="true">●</span><span>Prestataire vérifié par l'équipe KoudMain.</span></li>
                    </ul>
                </div>
            </aside>
        </div>
    </section>

    {{-- Autres prestations --}}
    @foreach ([['Du même prestataire', $memePrestataire], ['Dans la même catégorie', $memeCategorie]] as [$titreBloc, $cartes])
        @if ($cartes !== [])
            <section class="mx-auto mt-16 w-full max-w-6xl px-5 sm:px-8">
                <h2 class="text-xl font-semibold">{{ $titreBloc }}</h2>
                <x-island nom="Catalogue" class="mt-5" :donnees="['prestations' => $cartes, 'large' => true]">
                    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($cartes as $carte)
                            <li><x-prestation-repli :carte="$carte" /></li>
                        @endforeach
                    </ul>
                </x-island>
            </section>
        @endif
    @endforeach
</x-layouts.app>
