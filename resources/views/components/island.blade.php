{{--
    Île React : un bloc de page pris en charge par React (voir resources/js/islands.jsx).
    Le contenu du slot est le « repli » : il est affiché tel quel aux moteurs de recherche et aux
    visiteurs sans JavaScript, puis remplacé par le composant animé une fois le script chargé.

    <x-island nom="Landing" :donnees="['domaines' => [...]]"> ...repli... </x-island>

    Les données passent en JSON dans un attribut. Blade échappe les guillemets et les « & » :
    le navigateur les restitue intacts, et aucune donnée ne peut sortir de l'attribut.
--}}
@props(['nom', 'donnees' => []])
<div data-island="{{ $nom }}" data-props="{{ json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}" {{ $attributes }}>{{ $slot }}</div>
