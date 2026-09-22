@use('App\Support\Format')
<x-layouts.espace titre="Vue d'ensemble">
    <div data-region="tableau" data-region-evenements="commande notification">
    <x-espace.entete :etiquette="\Illuminate\Support\Str::ucfirst(now()->translatedFormat('l j F Y'))" titre="Bonjour," :suite="$user->prenom"
                     intro="Gérez vos offres, suivez les demandes de vos clients et faites grandir votre activité.">
        <a href="{{ route('prestataire.prestations.index') }}" class="btn"><x-icone nom="mallette" taille="size-4" /><span>Mes prestations</span></a>
        <a href="{{ route('prestataire.prestations.creer') }}" class="btn btn-plein"><x-icone nom="plus" taille="size-4" /><span>Nouvelle prestation</span></a>
    </x-espace.entete>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-espace.stat data-reveal libelle="Solde du wallet" :valeur="Format::montant($solde)" unite="FCFA" icone="portefeuille" note="Disponible" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Revenus encaissés" :valeur="Format::montant($revenus)" unite="FCFA" icone="coche"
                       :note="$aRecevoir > 0 ? Format::fcfa($aRecevoir).' à recevoir' : 'Total versé sur votre wallet'" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Prestations" :valeur="$nbPrestations" icone="mallette"
                       :note="$nbPrestations > 0 ? $nbPubliees.' visible'.($nbPubliees > 1 ? 's' : '').' dans le catalogue' : 'Aucune pour le moment'" />
        <x-espace.stat data-reveal style="--i: 3" libelle="Note moyenne" :valeur="$noteMoyenne !== null ? Format::note($noteMoyenne) : '—'" :unite="$noteMoyenne !== null ? '/ 5' : null" icone="etoile"
                       :note="$nbAvis > 0 ? Format::pluriel($nbAvis, 'avis').' de clients' : 'Pas encore d\'avis'" />
    </div>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <x-espace.panneau titre="Activité récente" etiquette="À ne pas manquer" :lien="route('prestataire.commandes')" data-reveal>
            @if ($recentes->isEmpty())
                <x-espace.vide icone="liste" titre="Aucune commande pour le moment"
                               texte="Publiez une prestation pour commencer à recevoir des demandes.">
                    <a href="{{ route('prestataire.prestations.creer') }}" class="btn btn-petit"><span>Publier une prestation</span></a>
                </x-espace.vide>
            @else
                <ul role="list" class="divide-y divide-line">
                    @foreach ($recentes as $commande)
                        <li class="flex flex-wrap items-center gap-x-3.5 gap-y-2 px-5 py-3.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-deep"><x-icone nom="colis" taille="size-4" /></span>
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="truncate text-sm font-medium"><a href="{{ route('prestataire.commandes.voir', $commande) }}" class="hover:text-accent">{{ $commande->prestations->first()?->titre ?? 'Commande n° '.$commande->id }}</a></p>
                                <p class="truncate text-sm text-faint">{{ $commande->client->nom_complet }} · {{ $commande->created_at->translatedFormat('j M, H:i') }}</p>
                            </div>
                            <x-espace.statut :statut="$commande->statut" />
                            <span class="text-sm font-medium tabular-nums">{{ Format::fcfa($commande->montant_total) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-espace.panneau>

        <x-espace.panneau titre="Actions rapides" etiquette="À portée de main" data-reveal style="--i: 1">
            <div class="divide-y divide-line">
                <x-espace.action :href="route('prestataire.prestations.creer')" icone="plus" titre="Publier une prestation" texte="Présentez votre savoir-faire" />
                <x-espace.action :href="route('prestataire.commandes')" icone="liste" titre="Voir les commandes"
                                 :texte="$enAttente > 0 ? Format::pluriel($enAttente, 'demande').' à traiter' : 'Aucune demande à traiter'" />
                <x-espace.action :href="route('prestataire.disponibilites')" icone="calendrier" titre="Mes disponibilités" texte="Vos horaires de la semaine" />
                <x-espace.action :href="route('prestataire.wallet')" icone="portefeuille" titre="Wallet et retraits" texte="Suivez vos encaissements" />
                <x-espace.action :href="route('prestataires.voir', $user)" icone="oeil" titre="Mon profil public" texte="Ce que voient les clients" />
                <x-espace.action :href="route('compte.profil').'#titre-bio'" icone="crayon" titre="Ma présentation"
                                 :texte="filled($user->bio) ? 'Modifier votre présentation' : 'Présentez-vous aux clients'" />
            </div>
        </x-espace.panneau>
    </div>
    </div>
</x-layouts.espace>
