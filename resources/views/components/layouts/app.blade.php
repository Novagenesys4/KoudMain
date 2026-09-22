@props(['titre' => null, 'description' => null, 'image' => null, 'canonique' => null, 'robots' => null])
@php
    // Adresses et données transmises à l'en-tête React (un seul endroit : le serveur reste maître des URL).
    $accueil = route('accueil');
    $urls = ['accueil' => $accueil, 'connexion' => route('connexion'), 'inscription' => route('inscription')];
    $liens = [
        ['libelle' => 'Domaines', 'href' => $accueil.'#domaines'],
        ['libelle' => 'Comment ça marche', 'href' => $accueil.'#etapes'],
    ];

    if (auth()->check()) {
        $urls += [
            'espace' => route('tableau-de-bord'),
            'profil' => route('compte.profil'),
            'motdepasse' => route('compte.mot-de-passe'),
            'deconnexion' => route('deconnexion'),
        ];

        // Seul un prestataire validé peut gérer des prestations.
        if (auth()->user()->aLeRole('prestataire')) {
            $urls['prestations'] = route('prestataire.prestations.index');
        }
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['titre' => $titre, 'description' => $description, 'image' => $image, 'canonique' => $canonique, 'robots' => $robots])
</head>
<body class="flex min-h-screen flex-col">
    <x-chargement />
    {{-- En-tête : île React (flottant, verre dépoli). Le repli ci-dessous sert sans JavaScript. --}}
    <x-island nom="SiteHeader" class="sticky top-0 z-50"
              :donnees="[
                  'liens' => $liens,
                  'connecte' => auth()->check(),
                  'prenom' => auth()->user()?->prenom ?? '',
                  'urls' => $urls,
                  'csrf' => csrf_token(),
              ]">
        <a class="lien-evitement" href="#contenu">Aller au contenu principal</a>
        <header class="border-b border-line">
            <div class="mx-auto flex min-h-20 w-full max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-4 sm:px-8">
                <a href="{{ $accueil }}" aria-label="KoudMain, accueil"><x-logo /></a>

                <nav aria-label="Navigation principale" class="flex flex-wrap items-center gap-x-5 gap-y-1 sm:gap-x-7">
                    @auth
                        <a class="nav-lien" href="{{ $urls['espace'] }}">Mon espace</a>
                        <a class="nav-lien" href="{{ $urls['profil'] }}">Mon profil</a>
                        <form method="POST" action="{{ $urls['deconnexion'] }}">
                            @csrf
                            <button type="submit" class="nav-lien cursor-pointer">Se déconnecter</button>
                        </form>
                    @else
                        <a class="nav-lien" href="{{ $urls['connexion'] }}">Connexion</a>
                        <a class="nav-lien" href="{{ $urls['inscription'] }}">Créer un compte</a>
                    @endauth
                </nav>
            </div>
        </header>
    </x-island>

    <main id="contenu" tabindex="-1" class="flex-1 focus:outline-none">
        <x-messages />
        {{ $slot }}
    </main>

    <footer class="mt-24 border-t border-line bg-deep">
        <div class="mx-auto w-full max-w-6xl px-5 pb-8 pt-14 sm:px-8">
            <div class="grid gap-12 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)]">
                <div>
                    <a href="{{ $accueil }}" aria-label="KoudMain, accueil"><x-logo class="text-4xl" /></a>
                    <p class="mt-4 max-w-sm text-soft">Des prestataires de confiance près de chez vous : coiffure, plomberie, laverie, garde d'enfants. Vous payez en sécurité, le prestataire est réglé une fois la prestation terminée.</p>
                </div>

                <nav aria-label="Découvrir">
                    <p class="etiquette">Découvrir</p>
                    <ul class="mt-5 grid gap-3">
                        @foreach ($liens as $lien)
                            <li><a class="nav-lien" href="{{ $lien['href'] }}">{{ $lien['libelle'] }}</a></li>
                        @endforeach
                        @if (Route::has('composants'))
                            <li><a class="nav-lien" href="{{ route('composants') }}">Kit d'interface</a></li>
                        @endif
                    </ul>
                </nav>

                <nav aria-label="Compte">
                    <p class="etiquette">Mon compte</p>
                    <ul class="mt-5 grid gap-3">
                        @auth
                            <li><a class="nav-lien" href="{{ $urls['espace'] }}">Mon espace</a></li>
                            <li><a class="nav-lien" href="{{ $urls['profil'] }}">Mon profil</a></li>
                            <li><a class="nav-lien" href="{{ $urls['motdepasse'] }}">Mot de passe</a></li>
                        @else
                            <li><a class="nav-lien" href="{{ $urls['connexion'] }}">Connexion</a></li>
                            <li><a class="nav-lien" href="{{ $urls['inscription'] }}">Créer un compte</a></li>
                        @endauth
                    </ul>
                </nav>
            </div>

            <div class="mt-14 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-6 font-mono text-[0.6875rem] uppercase tracking-[0.12em] text-faint">
                <p>© {{ date('Y') }} KoudMain · Côte d'Ivoire</p>
                <p>Paiement sécurisé par séquestre</p>
            </div>
        </div>
    </footer>
</body>
</html>
