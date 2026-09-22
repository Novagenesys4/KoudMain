{{-- Bloc de contenu : titre, lien facultatif à droite (`lien` + `lienLibelle`), zone `actions` facultative, puis le contenu. --}}
@props(['titre', 'etiquette' => null, 'lien' => null, 'lienLibelle' => 'Voir tout', 'actions' => null])
<section {{ $attributes->class('min-w-0 overflow-hidden rounded-2xl border border-line bg-surface') }}>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line px-5 py-4">
        <div class="min-w-0">
            @if ($etiquette)<p class="text-xs font-medium text-faint">{{ $etiquette }}</p>@endif
            <h2 class="text-lg">{{ $titre }}</h2>
        </div>
        @if ($lien)
            <a href="{{ $lien }}" class="lien inline-flex items-center gap-1.5 text-sm">{{ $lienLibelle }}<x-icone nom="fleche-droite" taille="size-3.5" /></a>
        @endif
        {{ $actions }}
    </div>
    {{ $slot }}
</section>
