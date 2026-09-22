@use('App\Support\Format')
@use('App\Enums\ActionCommande')
@php
    $titres = ['client' => ['Client', 'Mes', 'commandes', 'Suivez chaque commande, de la demande au paiement du prestataire.'],
               'prestataire' => ['Prestataire', 'Les', 'commandes', 'Les demandes de vos clients : acceptez, réalisez, terminez. Vous êtes payé quand le client confirme.'],
               'admin' => ['Administration', 'Toutes les', 'commandes', 'Toutes les commandes de la plateforme. Les litiges attendent votre arbitrage.']][$role];
    $total = array_sum($compteurs);
@endphp
<x-layouts.espace titre="Commandes" :recherche="false">
    <div data-region="commandes" data-region-evenements="commande">
    <x-espace.entete :etiquette="$titres[0]" :titre="$titres[1]" :suite="$titres[2]" :intro="$titres[3]">
        @if ($role === 'client')
            <a href="{{ route('client.catalogue') }}" class="btn btn-plein"><x-icone nom="recherche" taille="size-4" /><span>Trouver un service</span></a>
        @endif
    </x-espace.entete>

    <nav class="mb-5 flex flex-wrap gap-2" aria-label="Filtrer par statut">
        <a href="{{ route($role.'.commandes') }}" @class(['puce', 'bg-ink text-paper' => $statut === null]) @if ($statut === null) aria-current="page" @endif>Toutes <span class="tabular-nums opacity-70">{{ $total }}</span></a>
        @foreach ($statuts as $s)
            <a href="{{ route($role.'.commandes', ['statut' => $s->value]) }}" @class(['puce', 'bg-ink text-paper' => $statut === $s]) data-nuance="{{ $s->nuance() }}" @if ($statut === $s) aria-current="page" @endif>
                {{ $s->libelle() }} <span class="tabular-nums opacity-70">{{ $compteurs[$s->value] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <x-espace.panneau titre="Commandes" :etiquette="Format::pluriel($commandes->total(), 'commande')" data-reveal>
        @if ($commandes->isEmpty())
            @if ($role === 'client')
                <x-espace.vide icone="colis" :titre="$statut ? 'Aucune commande « '.mb_strtolower($statut->libelle()).' »' : 'Vous n\'avez pas encore de commande'"
                               texte="Choisissez une prestation dans le catalogue, un créneau, et votre commande part chez le prestataire.">
                    <a href="{{ route('client.catalogue') }}" class="btn btn-petit"><span>Parcourir le catalogue</span></a>
                </x-espace.vide>
            @else
                <x-espace.vide icone="colis" :titre="$statut ? 'Aucune commande « '.mb_strtolower($statut->libelle()).' »' : 'Aucune commande pour le moment'"
                               :texte="$role === 'prestataire' ? 'Les demandes de vos clients apparaîtront ici. Pensez à publier vos prestations et à indiquer vos disponibilités.' : 'Les commandes des clients apparaîtront ici.'" />
            @endif
        @else
            <ul role="list" class="divide-y divide-line">
                @foreach ($commandes as $commande)
                    @php
                        $ligne = $commande->prestations->first();
                        $partie = $role === 'client' ? $commande->prestataire : $commande->client;
                        $actions = $role === 'admin' ? [] : ActionCommande::possibles($commande, $utilisateur);
                    @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="truncate font-medium">
                                <a href="{{ route($role.'.commandes.voir', $commande) }}" class="hover:text-accent">{{ $ligne?->titre ?? 'Prestation supprimée' }}@if ($ligne && $ligne->pivot->quantite > 1) <span class="text-soft">× {{ $ligne->pivot->quantite }}</span>@endif</a>
                                <span class="ml-1 text-sm font-normal text-faint">n° {{ $commande->id }}</span>
                            </p>
                            <p class="truncate text-sm text-faint">
                                @if ($role === 'admin')
                                    {{ $commande->client->nom_complet }} → {{ $commande->prestataire->nom_complet }}
                                @else
                                    {{ $role === 'client' ? 'Prestataire' : 'Client' }} : {{ $partie->nom_complet }}
                                @endif
                                @if ($commande->date_souhaitee) · {{ $commande->date_souhaitee->translatedFormat('D j M \à H:i') }}@endif
                            </p>
                        </div>
                        <x-espace.statut :statut="$commande->statut" />
                        <span class="text-right text-sm font-medium tabular-nums">{{ Format::fcfa($commande->montant_total) }}<span class="flex items-center justify-end gap-1 text-xs font-normal text-faint"><x-icone :nom="$commande->mode_paiement->icone()" taille="size-3" />{{ $commande->mode_paiement->court() }}</span></span>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-commande.actions :commande="$commande" :actions="$actions" :role="$role" compact />
                            <a href="{{ route($role.'.commandes.voir', $commande) }}" class="btn btn-petit"><span>{{ $role === 'admin' && $commande->statut === \App\Enums\StatutCommande::Litige ? 'Arbitrer' : 'Détails' }}</span></a>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $commandes->links('pagination.espace') }}
        @endif
    </x-espace.panneau>
    </div>
</x-layouts.espace>
