{{-- Avatar : photo de profil si elle existe, sinon initiales sur aplat (même rendu que le composant React « Avatar »). --}}
@props(['utilisateur', 'taille' => 'size-12 text-base'])
@php
    $teinte = \App\Support\Presentateur::teinte($utilisateur);
    $fond = ['amber' => 'bg-amber-tint text-accent', 'teal' => 'bg-teal-tint text-teal', 'rose' => 'bg-rose-tint text-rose', 'sable' => 'bg-gold-tint text-gold'][$teinte];
    $initiales = mb_strtoupper(mb_substr($utilisateur->prenom, 0, 1).mb_substr($utilisateur->nom, 0, 1));
    $photo = $utilisateur->avatar?->url();
@endphp
@if ($photo)
    <img src="{{ $photo }}" alt="" loading="lazy" decoding="async" {{ $attributes->class(['shrink-0 rounded-full bg-deep object-cover', $taille]) }}>
@else
    <span aria-hidden="true" {{ $attributes->class(['grid shrink-0 place-items-center rounded-full font-semibold leading-none', $fond, $taille]) }}>{{ $initiales }}</span>
@endif
