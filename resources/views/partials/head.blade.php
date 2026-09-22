<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@auth<meta name="csrf-token" content="{{ csrf_token() }}">@endauth
<title>{{ isset($titre) && $titre ? $titre.' — KoudMain' : 'KoudMain — Services à domicile en Côte d\'Ivoire' }}</title>
<meta name="description" content="{{ $description ?? 'KoudMain met en relation des clients et des prestataires de confiance : coiffure, plomberie, laverie, garde d\'enfants. Paiement sécurisé par séquestre.' }}">
@php
    $titreComplet = isset($titre) && $titre ? $titre.' — KoudMain' : 'KoudMain — Services à domicile en Côte d\'Ivoire';
    $texte = $description ?? 'KoudMain met en relation des clients et des prestataires de confiance : coiffure, plomberie, laverie, garde d\'enfants. Paiement sécurisé par séquestre.';
    $adresse = ($canonique ?? null) ?: url()->current();
    $imageAbsolue = ($image ?? null) ? (\Illuminate\Support\Str::startsWith($image, ['http://', 'https://']) ? $image : url($image)) : null;
@endphp
<link rel="canonical" href="{{ $adresse }}">
@if (! empty($robots))<meta name="robots" content="{{ $robots }}">@endif
{{-- Aperçu quand le lien est partagé (WhatsApp, Facebook...) : titre, description et première photo. --}}
<meta property="og:site_name" content="KoudMain">
<meta property="og:locale" content="fr_FR">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $titreComplet }}">
<meta property="og:description" content="{{ $texte }}">
<meta property="og:url" content="{{ $adresse }}">
@if ($imageAbsolue)<meta property="og:image" content="{{ $imageAbsolue }}">@endif
<meta name="twitter:card" content="{{ $imageAbsolue ? 'summary_large_image' : 'summary' }}">
<meta name="theme-color" content="#f5f3ee" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#14130f" media="(prefers-color-scheme: dark)">

{{-- Thème posé AVANT l'affichage : pas de flash de couleur. Priorité : choix mémorisé, sinon préférence du système. --}}
<script>
    (function () {
        var racine = document.documentElement, theme = null;
        racine.classList.add('js');
        try { theme = localStorage.getItem('km-theme'); } catch (e) {}
        if (theme !== 'light' && theme !== 'dark') {
            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        racine.setAttribute('data-theme', theme);
    })();
</script>

{{-- Les polices (Fraunces, DM Sans, DM Mono) sont servies par le site lui-même, via la feuille de style : aucun service externe. --}}
@viteReactRefresh
@vite(['resources/css/app.css', 'resources/js/app.js'])
