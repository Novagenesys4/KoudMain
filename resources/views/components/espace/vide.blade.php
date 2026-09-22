{{-- État vide : dit clairement qu'il n'y a rien, et propose la suite (les boutons, dans le slot). --}}
@props(['titre', 'texte' => null, 'icone' => null])
<div {{ $attributes->class('px-6 py-12 text-center') }}>
    @if ($icone)
        <span class="mx-auto mb-4 grid size-11 place-items-center rounded-full bg-deep text-soft"><x-icone :nom="$icone" taille="size-5" /></span>
    @endif
    <h3 class="text-lg font-semibold">{{ $titre }}</h3>
    @if ($texte)<p class="mx-auto mt-2 max-w-md text-soft">{{ $texte }}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-5 flex flex-wrap items-center justify-center gap-3">{{ $slot }}</div>@endif
</div>
