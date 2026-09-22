{{-- Icône SVG inline (voir App\Support\Icones). La taille se règle avec `taille` (classes Tailwind), jamais avec class="size-…". --}}
@props(['nom', 'taille' => 'size-[1.125rem]'])
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" {{ $attributes->class(['shrink-0', $taille]) }}>{!! \App\Support\Icones::traces($nom) !!}</svg>
