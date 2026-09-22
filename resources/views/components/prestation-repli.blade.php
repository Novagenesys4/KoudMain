{{-- Carte simple (sans JavaScript) : ce que voient les moteurs de recherche et les visiteurs sans script.
     Remplacée par la carte animée (ServiceCard) dès que React est chargé. --}}
@props(['carte'])
<article class="flex h-full flex-col rounded-2xl border border-line bg-surface p-4 shadow-soft">
    <p class="text-xs font-medium text-soft">{{ $carte['metier'] }}</p>
    <h3 class="mt-1 text-[0.9375rem] font-semibold leading-snug"><a href="{{ $carte['url'] }}" class="lien">{{ $carte['titre'] }}</a></h3>
    <p class="mt-2 text-sm">{{ $carte['prestataire'] }} <span class="text-soft">· {{ $carte['quartier'] }}</span></p>
    <p class="mt-auto pt-4 text-xl font-semibold tracking-tight">{{ \App\Support\Format::montant($carte['prix']) }} <span class="text-xs font-medium text-soft">{{ config('koudmain.devise') }}</span></p>
</article>
