{{-- En-tête d'une page d'espace : petite étiquette, titre (la suite en gris), introduction, et à droite les actions (le slot). --}}
@props(['titre', 'suite' => null, 'etiquette' => null, 'intro' => null])
<div class="mb-8 flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
    <div class="min-w-0">
        @if ($etiquette)<p class="etiquette">{{ $etiquette }}</p>@endif
        <h1 class="mt-2 text-3xl sm:text-4xl">{{ $titre }}@if ($suite) <em>{{ $suite }}</em>@endif</h1>
        @if ($intro)<p class="mt-3 max-w-2xl text-soft">{{ $intro }}</p>@endif
    </div>
    @if (! $slot->isEmpty())
        <div class="flex flex-wrap items-center gap-3">{{ $slot }}</div>
    @endif
</div>
