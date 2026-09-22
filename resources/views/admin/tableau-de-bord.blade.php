@use('App\Support\Format')
<x-layouts.espace titre="Tableau de bord" :recherche="false">
    <div data-region="tableau" data-region-evenements="commande retrait admin">
    <x-espace.entete :etiquette="\Illuminate\Support\Str::ucfirst(now()->translatedFormat('l j F Y'))" titre="Tableau de" suite="bord"
                     intro="L'activité de la plateforme, et ce qui attend votre décision.">
        <a href="{{ route('admin.prestataires') }}" class="btn"><x-icone nom="utilisateur-valide" taille="size-4" /><span>Prestataires</span></a>
    </x-espace.entete>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-espace.stat data-reveal libelle="Clients" :valeur="$clients" icone="utilisateurs" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Prestataires validés" :valeur="$prestataires" icone="utilisateur-valide" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Profils à valider" :valeur="$enAttente" icone="horloge" :note="$enAttente > 0 ? 'Ils attendent votre réponse' : 'Rien en attente'" />
        <x-espace.stat data-reveal style="--i: 3" libelle="Commandes" :valeur="$commandes" icone="colis" :note="$litiges > 0 ? Format::pluriel($litiges, 'litige').' à traiter' : 'Aucun litige'" />
    </div>

    @if ($litigesListe->isNotEmpty())
        <x-espace.panneau class="mt-6" titre="Litiges à arbitrer" etiquette="Argent bloqué en séquestre" :lien="route('admin.commandes', ['statut' => 'litige'])" data-reveal>
            <ul role="list" class="divide-y divide-line">
                @foreach ($litigesListe as $commande)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="truncate text-sm font-medium">{{ $commande->prestations->first()?->titre ?? 'Commande n° '.$commande->id }} <span class="font-normal text-faint">n° {{ $commande->id }}</span></p>
                            <p class="truncate text-sm text-faint">{{ $commande->client->nom_complet }} → {{ $commande->prestataire->nom_complet }}</p>
                        </div>
                        <span class="text-sm font-medium tabular-nums">{{ Format::fcfa($commande->montant_total) }}</span>
                        <a href="{{ route('admin.commandes.voir', $commande) }}" class="btn btn-petit"><span>Arbitrer</span></a>
                    </li>
                @endforeach
            </ul>
        </x-espace.panneau>
    @endif

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <x-espace.panneau titre="Profils à valider" etiquette="Modération" :lien="route('admin.prestataires')" data-reveal>
            @if ($aValider->isEmpty())
                <x-espace.vide icone="coche" titre="Tout est à jour" texte="Aucun prestataire n'attend de validation pour le moment." />
            @else
                @include('admin._a-valider', ['liste' => $aValider])
            @endif
        </x-espace.panneau>

        <x-espace.panneau titre="En un coup d'œil" etiquette="La plateforme" data-reveal style="--i: 1">
            <dl class="divide-y divide-line text-sm">
                <div class="flex items-center justify-between gap-4 px-5 py-3.5"><dt class="text-soft">Prestations publiées</dt><dd class="font-medium tabular-nums">{{ $publiees }} <span class="font-normal text-faint">/ {{ $prestations }}</span></dd></div>
                <div class="flex items-center justify-between gap-4 px-5 py-3.5"><dt class="text-soft">Catégories</dt><dd class="font-medium tabular-nums">{{ $categories }}</dd></div>
                <div class="flex items-center justify-between gap-4 px-5 py-3.5"><dt class="text-soft">Services</dt><dd class="font-medium tabular-nums">{{ $services }}</dd></div>
                <div class="flex items-center justify-between gap-4 px-5 py-3.5"><dt class="text-soft">Commandes en litige</dt><dd class="font-medium tabular-nums">{{ $litiges }}</dd></div>
                <div class="flex items-center justify-between gap-4 px-5 py-3.5"><dt class="text-soft">Retraits à traiter</dt><dd class="font-medium tabular-nums">{{ $retraitsEnAttente }}</dd></div>
            </dl>
            <div class="divide-y divide-line border-t border-line">
                <x-espace.action :href="route('admin.catalogue')" icone="calques" titre="Gérer le catalogue" texte="Catégories et services" />
                <x-espace.action :href="route('admin.commandes')" icone="liste" titre="Voir les commandes" texte="Toutes les commandes" />
                <x-espace.action :href="route('admin.retraits')" icone="portefeuille" titre="Traiter les retraits" :texte="$retraitsEnAttente > 0 ? Format::pluriel($retraitsEnAttente, 'demande').' en attente' : 'Aucune demande en attente'" />
                <x-espace.action :href="route('admin.utilisateurs')" icone="utilisateurs" titre="Gérer les utilisateurs" texte="Rechercher, supprimer" />
            </div>
        </x-espace.panneau>
    </div>
    </div>
</x-layouts.espace>
