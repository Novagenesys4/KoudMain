{{-- Ligne d'action rapide : icône, titre, précision, flèche. `bientot` : la fonction arrive plus tard (affiché honnêtement). --}}
@props(['href', 'icone', 'titre', 'texte' => null, 'bientot' => false])
<a href="{{ $href }}" class="group flex items-center gap-3.5 px-5 py-3.5 transition-colors hover:bg-deep/50">
    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-deep text-ink"><x-icone :nom="$icone" taille="size-4" /></span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-medium">{{ $titre }}</span>
        @if ($texte)<span class="block text-sm text-faint">{{ $texte }}</span>@endif
    </span>
    @if ($bientot)<span class="statut">Bientôt</span>@endif
    <x-icone nom="chevron-droite" taille="size-4" class="text-faint transition-transform group-hover:translate-x-0.5" />
</a>
