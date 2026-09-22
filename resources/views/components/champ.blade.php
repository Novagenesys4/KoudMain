@props([
    'nom',
    'libelle',
    'type' => 'text',
    'valeur' => null,
    'aide' => null,
    'requis' => true,
])
@php
    $id = 'champ-'.$nom;
    $erreur = $errors->first($nom);
    $decrit = trim(($aide ? $id.'-aide ' : '').($erreur ? $id.'-erreur' : ''));
@endphp
<div class="champ">
    <label for="{{ $id }}">{{ $libelle }}@unless ($requis) <span class="text-faint font-normal">(facultatif)</span>@endunless</label>
    <div class="champ-saisie">
        <input
            id="{{ $id }}"
            name="{{ $nom }}"
            type="{{ $type }}"
            @if ($type !== 'password') value="{{ old($nom, $valeur) }}" @endif
            @if ($requis) required @endif
            @if ($erreur) aria-invalid="true" @endif
            @if ($decrit !== '') aria-describedby="{{ $decrit }}" @endif
            {{ $attributes }}
        >
        @if ($type === 'password')
            <button type="button" class="champ-oeil" data-toggle-mdp aria-controls="{{ $id }}" aria-pressed="false">Afficher</button>
        @endif
    </div>
    @if ($aide)<p id="{{ $id }}-aide" class="champ-aide">{{ $aide }}</p>@endif
    @if ($erreur)<p id="{{ $id }}-erreur" class="champ-erreur">{{ $erreur }}</p>@endif
</div>
