{{-- Note moyenne. Sans avis : « Nouveau » (jamais une fausse note). --}}
@props(['note' => null, 'nombre' => 0, 'seule' => false])
@if ($note !== null && $nombre > 0)
    <span {{ $attributes->class('inline-flex items-center gap-1.5') }}>
        <svg class="size-4 fill-amber text-amber" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        <span class="font-semibold text-ink">{{ \App\Support\Format::note($note) }}</span>
        @unless ($seule)<span class="text-soft">({{ \App\Support\Format::pluriel($nombre, 'avis', 'avis') }})</span>@endunless
        <span class="sr-only">, note sur 5</span>
    </span>
@else
    <span {{ $attributes->class('inline-flex items-center gap-1.5 text-soft') }}>Nouveau : pas encore d'avis</span>
@endif
