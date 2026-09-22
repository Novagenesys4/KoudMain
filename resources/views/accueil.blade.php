@php
    // Ce que React reçoit : des données réelles (domaines, chiffres), jamais de contenu inventé.
    $donnees = [
        'domaines' => $categories->map(fn ($c) => ['id' => $c->id, 'nom' => $c->nom, 'services_count' => $c->services->count()])->values()->all(),
        'stats' => $stats,
        'prestations' => $prestations,
        'quartiers' => $quartiers,
        'nbPrestataires' => $nbPrestataires,
        'connecte' => auth()->check(),
        'urls' => [
            'inscription' => route('inscription'),
            'connexion' => route('connexion'),
            'espace' => route('tableau-de-bord'),
            'suggestions' => route('recherche.suggestions'),
        ],
    ];
@endphp
<x-layouts.app>
    {{-- Île React « Landing » : page d'accueil animée. Le repli ci-dessous (même contenu, sans animation)
         est ce que voient les moteurs de recherche et les visiteurs sans JavaScript. --}}
    <x-island nom="Landing" :donnees="$donnees">
        <section class="halo-hero mx-auto grid w-full max-w-6xl gap-14 px-5 pb-8 pt-14 sm:px-8 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-20 lg:pt-24">
            <div>
                <p class="etiquette etiquette-trait">Services à domicile · Côte d'Ivoire</p>
                <h1 class="titre-hero mt-6">Le bon prestataire, <em>près de chez vous.</em></h1>
                <p class="mt-5 max-w-xl text-[1.0625rem] text-soft">
                    Coiffure, plomberie, laverie, garde d'enfants : KoudMain met en relation des clients et des
                    prestataires de confiance. Vous payez en sécurité, et le prestataire n'est réglé qu'une fois
                    la prestation terminée.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-x-8 gap-y-4">
                    @auth
                        <a href="{{ route('tableau-de-bord') }}" class="btn btn-plein"><span>Aller à mon espace</span></a>
                    @else
                        <a href="{{ route('inscription') }}" class="btn btn-plein"><span>Créer mon compte</span></a>
                        <a href="{{ route('connexion') }}" class="lien">J'ai déjà un compte</a>
                    @endauth
                </div>
            </div>

            <aside aria-labelledby="titre-domaines-repli">
                <h2 id="titre-domaines-repli" class="etiquette mb-5">Les domaines</h2>
                @if ($categories->isNotEmpty())
                    <ol class="divide-y divide-line border-y border-line">
                        @foreach ($categories as $categorie)
                            <li class="flex items-baseline justify-between gap-4 py-3.5">
                                <span class="text-lg font-semibold">{{ $categorie->nom }}</span>
                                @if ($categorie->services->count() > 0)
                                    <span class="text-sm text-soft">{{ $categorie->services->count() }} {{ $categorie->services->count() > 1 ? 'services' : 'service' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="border-t border-line pt-4 text-soft">Les domaines de services seront bientôt disponibles.</p>
                @endif
            </aside>
        </section>

        <section class="mx-auto mt-20 w-full max-w-6xl px-5 sm:px-8" aria-labelledby="titre-etapes-repli">
            <h2 id="titre-etapes-repli" class="max-w-2xl text-2xl sm:text-3xl">Trois étapes, <em>sans mauvaise surprise.</em></h2>
            <ol class="mt-10 grid gap-8 border-t border-line pt-8 md:grid-cols-3">
                @foreach ([
                    ['Choisir', 'Parcourez, depuis votre espace, les prestations proches de chez vous et comparez les prix, les disponibilités et les avis des autres clients.'],
                    ['Réserver', 'Vous commandez et payez depuis votre porte-monnaie. La somme est mise de côté : le prestataire ne la reçoit pas encore.'],
                    ['Valider', 'La prestation terminée, vous confirmez la réception : le paiement est libéré et vous pouvez noter le prestataire.'],
                ] as $i => [$titre, $texte])
                    <li>
                        <span class="text-2xl font-semibold text-accent" aria-hidden="true">{{ $i + 1 }}</span>
                        <h3 class="mt-3 text-lg">{{ $titre }}</h3>
                        <p class="mt-3 text-soft">{{ $texte }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mx-auto mt-20 w-full max-w-6xl px-5 sm:px-8" aria-labelledby="titre-prestataire-repli">
            <div class="grid gap-8 border-y border-line py-14 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <div>
                    <p class="etiquette">Vous êtes prestataire ?</p>
                    <h2 id="titre-prestataire-repli" class="mt-5 max-w-2xl text-2xl sm:text-3xl">Faites connaître votre savoir-faire, <em>soyez payé sans courir après.</em></h2>
                    <p class="mt-5 max-w-xl text-soft">Créez votre profil, indiquez vos prix et vos horaires. Un administrateur vérifie chaque prestataire avant sa mise en ligne.</p>
                </div>
                @guest
                    <a href="{{ route('inscription') }}" class="btn"><span>Proposer mes services</span></a>
                @endguest
            </div>
        </section>
    </x-island>
</x-layouts.app>
