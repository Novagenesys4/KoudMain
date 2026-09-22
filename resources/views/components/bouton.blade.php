@props(['chargement' => 'Un instant…', 'plein' => true])
<button type="submit" data-chargement="{{ $chargement }}" {{ $attributes->class(['btn', 'btn-plein' => $plein]) }}>
    <span data-libelle>{{ $slot }}</span>
    <svg class="fleche" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
</button>
