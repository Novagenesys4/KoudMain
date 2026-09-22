@use('App\Support\Format')
@php
    $zone = $prestataire->quartier->nom.', '.$prestataire->quartier->ville->nom;
    $description = $prestataire->bio
        ? \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $prestataire->bio), 155)
        : $prestataire->nom_complet.', prestataire à '.$prestataire->quartier->nom.' : ses prestations, ses prix et les avis de ses clients.';
    $premiere = $cartes[0]['photo'] ?? null;
@endphp
<x-layouts.app :titre="$prestataire->nom_complet" :description="$description" :image="$premiere"
               :canonique="route('prestataires.voir', $prestataire)" :robots="$page->currentPage() > 1 ? 'noindex,follow' : null">
    <section class="mx-auto w-full max-w-6xl px-5 pt-10 sm:px-8 lg:pt-14">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            <x-avatar :utilisateur="$prestataire" taille="size-24 text-3xl" />
            <div class="min-w-0">
                <p class="etiquette">Prestataire</p>
                <h1 class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-3xl sm:text-4xl">
                    {{ $prestataire->nom_complet }}
                    <span class="puce align-middle text-sm font-medium">Vérifié</span>
                </h1>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    <x-etoiles :note="$moyenne" :nombre="$nombreAvis" />
                    <span class="text-soft">{{ $zone }}</span>
                    <span class="text-soft">Sur KoudMain depuis {{ $prestataire->created_at->translatedFormat('F Y') }}</span>
                </div>
                @if ($prestataire->bio)
                    <p class="mt-5 max-w-2xl whitespace-pre-line leading-relaxed">{{ $prestataire->bio }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="mx-auto mt-14 w-full max-w-6xl border-t border-line px-5 pt-10 sm:px-8">
        <h2 class="text-xl font-semibold">Prestations <span class="font-normal text-soft">({{ $page->total() }})</span></h2>
        @if ($cartes === [])
            <p class="mt-4 max-w-xl text-soft">Ce prestataire n'a aucune prestation publiée pour le moment.</p>
        @else
            <x-island nom="Catalogue" class="mt-5" :donnees="['prestations' => $cartes, 'large' => true]">
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cartes as $carte)
                        <li><x-prestation-repli :carte="$carte" /></li>
                    @endforeach
                </ul>
            </x-island>
            {{ $page->onEachSide(1)->links('pagination.koudmain') }}
        @endif
    </section>

    <section class="mx-auto mt-14 w-full max-w-6xl border-t border-line px-5 pt-10 sm:px-8">
        <h2 class="text-xl font-semibold">Avis des clients</h2>
        @if ($avis->isEmpty())
            <p class="mt-4 max-w-xl text-soft">Aucun avis pour le moment.</p>
        @else
            <ul class="mt-5 grid gap-6 md:grid-cols-2">
                @foreach ($avis as $unAvis)
                    <li>
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <p class="font-medium">{{ $unAvis->client->prenom }} {{ mb_substr($unAvis->client->nom, 0, 1) }}.</p>
                            <p class="text-sm text-soft">{{ $unAvis->created_at->translatedFormat('j F Y') }}</p>
                        </div>
                        <x-etoiles :note="(float) $unAvis->note" :nombre="1" seule class="mt-1 text-sm" />
                        <p class="mt-1 text-sm text-soft">Pour <a href="{{ route('prestations.voir', $unAvis->prestation) }}" class="lien">{{ $unAvis->prestation->titre }}</a></p>
                        @if ($unAvis->commentaire)
                            <p class="mt-2 whitespace-pre-line leading-relaxed">{{ $unAvis->commentaire }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
