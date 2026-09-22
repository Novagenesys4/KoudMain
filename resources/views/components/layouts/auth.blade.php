@props(['titre' => null, 'variante' => 'connexion'])
<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['titre' => $titre])
</head>
{{-- La page défile normalement (plus de hauteur figée ni de défilement interne) : sur mobile, le clavier virtuel ne coupe plus
     les champs ; sur ordinateur, seul le formulaire défile, le panneau de marque reste fixe (sticky). --}}
<body class="min-h-dvh lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    <x-chargement />
    <a class="lien-evitement" href="#contenu">Aller au contenu principal</a>

    {{-- Panneau de marque : île React (titre animé, trajet de l'argent). Il reste sombre dans les deux thèmes. --}}
    <aside class="disque relative isolate overflow-hidden px-5 py-4 text-on-panel sm:px-8 sm:py-5 lg:sticky lg:top-0 lg:h-dvh lg:px-12 lg:py-[clamp(1.5rem,5vh,3rem)]" style="border-radius:0">
        <x-island nom="AuthShowcase" class="h-full" :donnees="['variante' => $variante, 'accueil' => route('accueil')]">
            <div class="flex h-full flex-col justify-between gap-10">
                <a href="{{ route('accueil') }}" class="w-fit text-on-panel [--logo-accent:var(--on-panel-accent)]" aria-label="KoudMain, retour à l'accueil"><x-logo /></a>
                <p class="hidden max-w-sm text-sm text-on-panel-soft lg:block">Le prestataire n'est réglé qu'une fois la prestation terminée : votre argent reste protégé jusque-là.</p>
            </div>
        </x-island>
    </aside>

    <div class="flex flex-col lg:min-h-dvh">
        <div class="flex shrink-0 justify-end px-5 pt-4 sm:px-8">
            <button type="button" class="theme-bascule" data-theme-toggle aria-pressed="false">
                <span class="pastille" aria-hidden="true"></span>
                <span data-theme-libelle>Thème clair</span>
            </button>
        </div>

        {{-- Le contenu est centré verticalement par la marge automatique (my-auto) quand il tient dans l'écran ; sinon la page
             défile simplement, sans rien couper en haut ni en bas. --}}
        <main id="contenu" tabindex="-1" class="mx-auto flex w-full max-w-lg flex-1 flex-col px-5 pb-10 pt-4 focus:outline-none sm:px-8 lg:py-8">
            <div class="my-auto">
                <x-messages :cadre="false" />
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
