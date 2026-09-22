{{--
    Gabarit des espaces connectés (client, prestataire, administrateur) : menu latéral + en-tête + contenu.
    Le menu vient de App\Support\Espace\Menu (un seul endroit pour les trois espaces).

    <x-layouts.espace titre="Mes prestations"> ... </x-layouts.espace>
      - page      : libellé du fil d'Ariane pour une page absente du menu (ex. « Nouvelle prestation ») ;
      - recherche : false pour masquer la recherche de l'en-tête (le catalogue a la sienne).
--}}
@props(['titre' => null, 'page' => null, 'recherche' => true])
@php
    $user = auth()->user()->loadMissing('avatar');
    $route = request()->route()?->getName();
    $menu = \App\Support\Espace\Menu::pour($user, $route);
    $libellePage = $page ?? $menu['courant'];

    // Temps réel : la page annonce au navigateur où écouter le serveur (flux SSE) et les compteurs de départ.
    $tempsReel = (bool) config('koudmain.temps_reel.actif');
    $messagesNonLus = $menu['espace'] === 'admin' ? 0 : \App\Support\Espace\Compteurs::messagesNonLus($user);
    $notificationsNonLues = \App\Support\Espace\Compteurs::notificationsNonLues($user);
    $avecMessages = $menu['espace'] !== 'admin';

    // Recherche de l'en-tête : le client cherche dans le catalogue, le prestataire dans ses prestations, l'admin n'en a pas.
    $rechercheCible = $recherche ? match ($menu['espace']) {
        'client' => ['route' => 'client.catalogue', 'libelle' => 'Rechercher un service…'],
        'prestataire' => ['route' => 'prestataire.prestations.index', 'libelle' => 'Rechercher une prestation…'],
        default => null,
    } : null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['titre' => $titre, 'robots' => 'noindex,nofollow'])
</head>
<body data-utilisateur="{{ $user->id }}" data-non-lus-messages="{{ $messagesNonLus }}" data-non-lues-notifications="{{ $notificationsNonLues }}"
      @if ($tempsReel) data-temps-reel="{{ route('temps-reel', [], false) }}" data-temps-reel-sonder="{{ route('temps-reel.sonder', [], false) }}" data-temps-reel-mode="{{ \App\Services\TempsReel\Diffuseur::mode() }}" @endif>
    <x-chargement />
    <a class="lien-evitement" href="#contenu">Aller au contenu principal</a>

    <div class="espace-voile" data-espace-voile></div>

    {{-- ---------------------------------------------------------------- Menu latéral --}}
    <aside id="espace-menu" class="espace-menu" data-espace-menu aria-label="Menu de l'{{ \Illuminate\Support\Str::lower($menu['racine']) }}">
        <div class="espace-marque">
            <a href="{{ route('accueil') }}" aria-label="KoudMain, retour au site"><x-logo /></a>
            <button type="button" class="espace-fermer" data-espace-fermer aria-label="Fermer le menu"><x-icone nom="fermer" /></button>
        </div>
        <p class="espace-role">{{ $menu['racine'] }}</p>

        <nav class="espace-nav" aria-label="Navigation de l'espace" data-region="menu" data-region-evenements="commande retrait admin">
            @foreach ($menu['groupes'] as $groupe)
                <div class="espace-groupe">
                    <p class="espace-groupe-titre" aria-hidden="true">{{ $groupe['titre'] }}</p>
                    <ul role="list" class="grid gap-0.5" aria-label="{{ $groupe['titre'] }}">
                        @foreach ($groupe['liens'] as $lien)
                            <li>
                                <a href="{{ $lien['href'] }}" class="espace-lien" @if ($lien['actif']) aria-current="page" @endif>
                                    <x-icone :nom="$lien['icone']" />
                                    <span>{{ $lien['libelle'] }}</span>
                                    @if (! empty($lien['live']))
                                        {{-- Pastille suivie en direct : présente même à zéro (masquée), le navigateur la remplit. --}}
                                        <span class="espace-pastille" data-badge="{{ $lien['live'] }}" @unless ($lien['badge']) hidden @endunless>{{ $lien['badge'] }}</span>
                                    @elseif ($lien['badge'])
                                        <span class="espace-pastille"><span class="sr-only">(</span>{{ $lien['badge'] }}<span class="sr-only">)</span></span>
                                    @elseif ($lien['bientot'])
                                        <span class="espace-bientot">Bientôt</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach

                        @if ($loop->last)
                            <li>
                                <form method="POST" action="{{ route('deconnexion') }}">
                                    @csrf
                                    <button type="submit" class="espace-lien"><x-icone nom="deconnexion" /><span>Déconnexion</span></button>
                                </form>
                            </li>
                        @endif
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="espace-pied" data-region="pied" data-region-evenements="commande retrait notification">
            @if ($menu['pied']['type'] === 'wallet')
                <a href="{{ $menu['pied']['href'] }}" class="espace-boite">
                    <small>Solde disponible</small>
                    <strong>{{ \App\Support\Format::fcfa($menu['pied']['solde']) }}</strong>
                    @if ($menu['pied']['progression'] !== null)
                        <div class="espace-jauge" aria-hidden="true"><i style="--p: {{ $menu['pied']['progression'] }}%"></i></div>
                        <div class="espace-jauge-legende"><span>Commandes terminées</span><span>{{ $menu['pied']['progression'] }} %</span></div>
                    @endif
                </a>
            @else
                <div class="espace-boite">
                    <small>Administration</small>
                    <strong>{{ \App\Support\Format::pluriel($menu['pied']['comptes'], 'compte') }}</strong>
                </div>
            @endif
        </div>
    </aside>

    {{-- ---------------------------------------------------------------- Corps --}}
    <div class="espace-corps">
        <header class="espace-haut">
            <button type="button" class="espace-icone espace-burger" data-espace-ouvrir aria-controls="espace-menu" aria-expanded="false" aria-label="Ouvrir le menu"><x-icone nom="menu" taille="size-5" /></button>

            <nav class="espace-fil" aria-label="Fil d'Ariane">
                <span>{{ $menu['racine'] }}</span>
                @if ($libellePage)
                    <x-icone nom="chevron-droite" taille="size-3.5" />
                    <strong aria-current="page">{{ $libellePage }}</strong>
                @endif
            </nav>

            @if ($rechercheCible)
                <form class="espace-recherche" method="GET" action="{{ route($rechercheCible['route']) }}" role="search">
                    <x-icone nom="recherche" taille="size-4" />
                    <input type="search" name="q" value="{{ $route === $rechercheCible['route'] ? \App\Support\Saisie::texte(request()->query('q')) : '' }}" maxlength="100" autocomplete="off"
                           placeholder="{{ $rechercheCible['libelle'] }}" aria-label="{{ $rechercheCible['libelle'] }}">
                </form>
            @endif

            <div class="espace-actions">
                <button type="button" class="espace-icone" data-theme-toggle aria-pressed="false" aria-label="Thème sombre" title="Changer de thème">
                    <x-icone nom="lune" class="icone-theme-lune" />
                    <x-icone nom="soleil" class="icone-theme-soleil" />
                </button>
                @if ($avecMessages)
                    <a href="{{ route('messages') }}" class="espace-icone" aria-label="Messages" title="Messages">
                        <x-icone nom="message" />
                        <span class="espace-point" data-badge="messages" @unless ($messagesNonLus > 0) hidden @endunless>{{ $messagesNonLus > 99 ? '99+' : $messagesNonLus }}</span>
                    </a>
                @endif
                {{-- La cloche : îlot React quand le temps réel est actif (liste déroulante, mise à jour en direct) ; sinon un simple lien. --}}
                @if ($tempsReel)
                    <x-island nom="Cloche" :donnees="['nonLues' => $notificationsNonLues, 'base' => route('notifications', [], false)]" class="contents">
                        <a href="{{ route('notifications') }}" class="espace-icone" aria-label="Notifications" title="Notifications">
                            <x-icone nom="cloche" />
                            @if ($notificationsNonLues > 0)<span class="espace-point">{{ $notificationsNonLues > 99 ? '99+' : $notificationsNonLues }}</span>@endif
                        </a>
                    </x-island>
                @else
                    <a href="{{ route('notifications') }}" class="espace-icone" aria-label="Notifications" title="Notifications">
                        <x-icone nom="cloche" />
                        @if ($notificationsNonLues > 0)<span class="espace-point">{{ $notificationsNonLues > 99 ? '99+' : $notificationsNonLues }}</span>@endif
                    </a>
                @endif
                <a href="{{ route('compte.profil') }}" class="espace-profil" title="Mon profil">
                    <x-avatar :utilisateur="$user" taille="size-9 text-xs" />
                    <span class="espace-profil-texte"><strong>{{ $user->nom_complet }}</strong><small>{{ $user->libelleRole() }}</small></span>
                </a>
            </div>
        </header>

        <main id="contenu" tabindex="-1" class="espace-contenu">
            <x-messages :cadre="false" />
            {{ $slot }}
        </main>
    </div>

    @if ($tempsReel)
        <x-island nom="Toasts" />
    @endif
</body>
</html>
