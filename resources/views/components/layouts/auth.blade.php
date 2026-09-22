@props(['titre' => null, 'variante' => 'connexion'])
<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['titre' => $titre])
</head>
<body class="flex h-dvh flex-col overflow-hidden lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    <x-chargement />
    <a class="lien-evitement" href="#contenu">Aller au contenu principal</a>

    {{-- Panneau de marque : île React (titre animé, trajet de l'argent). Il reste sombre dans les deux thèmes. --}}
    <aside class="disque relative isolate shrink-0 overflow-hidden px-6 py-8 text-on-panel sm:px-10 lg:h-dvh lg:px-14 lg:py-12" style="border-radius:0">
        <x-island nom="AuthShowcase" class="h-full" :donnees="['variante' => $variante, 'accueil' => route('accueil')]">
            <div class="flex h-full flex-col justify-between gap-10">
                <a href="{{ route('accueil') }}" class="w-fit text-on-panel [--logo-accent:var(--on-panel-accent)]" aria-label="KoudMain, retour à l'accueil"><x-logo /></a>
                <p class="hidden max-w-sm text-sm text-on-panel-soft lg:block">Le prestataire n'est réglé qu'une fois la prestation terminée : votre argent reste protégé jusque-là.</p>
            </div>
        </x-island>
    </aside>

    <div class="flex min-h-0 flex-1 flex-col lg:h-dvh">
        <div class="flex shrink-0 justify-end px-6 pt-5 sm:px-10">
            <button type="button" class="theme-bascule" data-theme-toggle aria-pressed="false">
                <span class="pastille" aria-hidden="true"></span>
                <span data-theme-libelle>Thème clair</span>
            </button>
        </div>

        {{-- overflow-y-auto : filet de sécurité si un écran est vraiment trop court pour l'étape affichée
             (resources/js/inscriptionEtapes.js). Le contenu est centré par la marge automatique de son
             enrobage (my-auto), pas par justify-center sur ce conteneur : centrer avec justify-content
             empêcherait d'atteindre par défilement ce qui dépasse en haut quand ça déborde. --}}
        <main id="contenu" tabindex="-1" class="mx-auto flex min-h-0 w-full max-w-xl flex-1 flex-col overflow-y-auto px-6 py-6 focus:outline-none sm:px-10 sm:py-10">
            <div class="my-auto">
                <x-messages :cadre="false" />
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
