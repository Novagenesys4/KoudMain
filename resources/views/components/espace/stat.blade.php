{{-- Un chiffre clé : libellé, valeur (avec unité), petite note. Pas d'effet au survol : ce n'est pas cliquable. `sombre` : tuile qui reste sombre dans les deux thèmes (ses couleurs viennent de .stat-sombre : on n'ajoute donc pas les classes utilitaires de la version claire, qui les écraseraient). --}}
@props(['libelle', 'valeur', 'icone' => null, 'unite' => null, 'note' => null, 'sombre' => false])
<div {{ $attributes->class(['rounded-2xl p-5', 'border border-line bg-surface' => ! $sombre, 'stat-sombre' => $sombre]) }}>
    <div @class(['stat-libelle flex items-center justify-between gap-3 text-sm', 'text-soft' => ! $sombre])>
        <span>{{ $libelle }}</span>
        @if ($icone)<x-icone :nom="$icone" taille="size-4" @class(['text-faint' => ! $sombre]) />@endif
    </div>
    <p class="mt-3 flex items-baseline gap-1.5 text-3xl font-semibold tracking-tight">
        {{ $valeur }}@if ($unite)<span @class(['stat-unite text-base font-normal', 'text-soft' => ! $sombre])>{{ $unite }}</span>@endif
    </p>
    @if ($note)<p @class(['stat-note mt-1.5 text-sm', 'text-faint' => ! $sombre])>{{ $note }}</p>@endif
</div>
