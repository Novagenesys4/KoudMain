{{-- Bandeau d'erreur DANS une boîte de formulaire (recharge, retrait, ajout de carte).
     Quand la boîte se rouvre après un refus, la page derrière est masquée : le message du haut de page ne se voit pas.
     Affiche le refus du service (session « erreur », si la boîte est celle qui vient d'être envoyée) et les erreurs de saisie des champs listés. --}}
@props(['nom', 'champs' => [], 'titre' => 'Rien n\'a été enregistré.'])
@php
    $erreurs = collect($champs)->flatMap(fn ($champ) => $errors->get($champ))->unique()->values();
    $refus = session('ouvrir') === $nom ? session('erreur') : null;
@endphp
<div class="dialogue-alerte" role="alert" data-dialogue-alerte @if (! $refus && $erreurs->isEmpty()) hidden @endif>
    <strong data-alerte-titre>{{ $titre }}</strong>
    <ul data-alerte-liste>
        @if ($refus)<li>{{ $refus }}</li>@endif
        @foreach ($erreurs as $erreur)<li>{{ $erreur }}</li>@endforeach
    </ul>
</div>
