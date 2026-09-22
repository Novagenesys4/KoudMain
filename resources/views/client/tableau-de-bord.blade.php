@use('App\Support\Format')
<x-layouts.espace titre="Vue d'ensemble">
    <div data-region="tableau" data-region-evenements="commande notification">
    <x-espace.entete :etiquette="\Illuminate\Support\Str::ucfirst(now()->translatedFormat('l j F Y'))" titre="Bonjour," :suite="$user->prenom"
                     intro="Retrouvez les services qui rendent vos journées plus simples.">
        <a href="{{ route('client.catalogue') }}" class="btn btn-plein"><x-icone nom="recherche" taille="size-4" /><span>Trouver un service</span></a>
    </x-espace.entete>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-espace.stat data-reveal libelle="Solde du wallet" :valeur="Format::montant($solde)" unite="FCFA" icone="portefeuille" note="Disponible" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Commandes passées" :valeur="$total" icone="colis" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Prestations terminées" :valeur="$terminees" icone="coche" />
        <x-espace.stat data-reveal style="--i: 3" libelle="En attente" :valeur="$enAttente" icone="horloge" note="Réponse du prestataire" />
    </div>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <x-espace.panneau titre="Commandes récentes" etiquette="Votre activité" :lien="route('client.commandes')" data-reveal>
            @if ($recentes->isEmpty())
                <x-espace.vide icone="colis" titre="Aucune commande pour le moment"
                               texte="Choisissez une prestation dans le catalogue : vos commandes apparaîtront ici.">
                    <a href="{{ route('client.catalogue') }}" class="btn btn-petit"><span>Parcourir le catalogue</span></a>
                </x-espace.vide>
            @else
                <ul role="list" class="divide-y divide-line">
                    @foreach ($recentes as $commande)
                        <li class="flex flex-wrap items-center gap-x-3.5 gap-y-2 px-5 py-3.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-deep"><x-icone nom="colis" taille="size-4" /></span>
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="truncate text-sm font-medium"><a href="{{ route('client.commandes.voir', $commande) }}" class="hover:text-accent">{{ $commande->prestations->first()?->titre ?? 'Commande n° '.$commande->id }}</a></p>
                                <p class="truncate text-sm text-faint">{{ $commande->prestataire->nom_complet }} · {{ $commande->created_at->translatedFormat('j M, H:i') }}</p>
                            </div>
                            <x-espace.statut :statut="$commande->statut" />
                            <span class="text-sm font-medium tabular-nums">{{ Format::fcfa($commande->montant_total) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-espace.panneau>

        <x-espace.panneau titre="Actions rapides" etiquette="Gagnez du temps" data-reveal style="--i: 1">
            <div class="divide-y divide-line">
                <x-espace.action :href="route('client.catalogue')" icone="recherche" titre="Parcourir les services" texte="Choisir un prestataire près de chez vous" />
                <x-espace.action :href="route('client.commandes')" icone="colis" titre="Suivre mes commandes"
                                 :texte="$enAttente > 0 ? Format::pluriel($enAttente, 'commande').' en attente' : 'Aucune commande en attente'" />
                <x-espace.action :href="route('client.wallet')" icone="portefeuille" titre="Mon wallet" texte="Recharger, consulter l'historique" />
            </div>
        </x-espace.panneau>
    </div>

    <x-espace.panneau class="mt-6" titre="Nouveautés du catalogue" etiquette="Récemment publié" :lien="route('client.catalogue')" lienLibelle="Tout le catalogue" data-reveal>
        @if ($nouveautes->isEmpty())
            <x-espace.vide titre="Le catalogue est vide pour le moment" texte="Les premières prestations arrivent bientôt." />
        @else
            <ul role="list" class="grid gap-5 p-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($nouveautes as $prestation)
                    @php($photo = $prestation->medias->first())
                    <li>
                        <a href="{{ route('prestations.voir', $prestation) }}" class="group block">
                            <span class="block aspect-[4/3] overflow-hidden rounded-xl bg-deep">
                                @if ($photo)
                                    <img src="{{ $photo->url() }}" alt="" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]">
                                @else
                                    <span class="grid size-full place-items-center text-faint"><x-icone nom="image" taille="size-6" /></span>
                                @endif
                            </span>
                            <span class="mt-3 block text-sm font-medium group-hover:text-accent">{{ $prestation->titre }}</span>
                            <span class="block text-sm text-faint">{{ $prestation->prestataire->nom_complet }} · {{ $prestation->service->nom }}</span>
                            <span class="mt-1 block text-sm font-medium tabular-nums">{{ Format::fcfa($prestation->prix) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-espace.panneau>
    </div>
</x-layouts.espace>
