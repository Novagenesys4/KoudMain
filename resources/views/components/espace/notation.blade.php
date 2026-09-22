{{-- Choisir une note de 1 à 5 étoiles. Ce sont de vrais boutons radio (clavier, lecteur d'écran, sans JavaScript) dessinés en étoiles.
     Les étoiles sont écrites de 5 à 1 et affichées à l'envers par la CSS : ainsi « survol / choix » allume les étoiles jusqu'à celle visée. --}}
@props(['nom' => 'note', 'id' => 'note', 'valeur' => 0])
<div {{ $attributes->class('notation') }} role="radiogroup" aria-label="Note sur 5">
    @for ($i = 5; $i >= 1; $i--)
        <input type="radio" name="{{ $nom }}" id="{{ $id }}-{{ $i }}" value="{{ $i }}" @checked((int) $valeur === $i) required>
        <label for="{{ $id }}-{{ $i }}" title="{{ $i }} étoile{{ $i > 1 ? 's' : '' }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z" stroke-width="1.6" stroke-linejoin="round"/></svg>
            <span class="sr-only">{{ $i }} sur 5</span>
        </label>
    @endfor
</div>
