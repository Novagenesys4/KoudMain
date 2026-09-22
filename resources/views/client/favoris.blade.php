<x-layouts.espace titre="Mes favoris" :recherche="false">
    <x-espace.entete etiquette="Mis de côté" titre="Mes" suite="favoris" intro="Les prestations que vous avez repérées, pour les retrouver et commander en un clic.">
        <a href="{{ route('client.catalogue') }}" class="btn btn-petit"><x-icone nom="recherche" taille="size-4" /><span>Parcourir le catalogue</span></a>
    </x-espace.entete>

    @if ($cartes === [])
        <x-espace.panneau titre="Favoris" etiquette="Aucun pour le moment" data-reveal>
            <x-espace.vide icone="coeur" titre="Vous n'avez pas encore de favori"
                           texte="Touchez le cœur d'une prestation dans le catalogue pour la retrouver ici, avec sa note et son prix.">
                <a href="{{ route('client.catalogue') }}" class="btn btn-petit"><span>Parcourir le catalogue</span></a>
            </x-espace.vide>
        </x-espace.panneau>
    @else
        <x-island nom="Catalogue" :donnees="['prestations' => $cartes, 'espace' => true, 'retirerAuRetrait' => true]">
            <ul class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                @foreach ($cartes as $carte)
                    <li><x-prestation-repli :carte="$carte" /></li>
                @endforeach
            </ul>
        </x-island>

        {{ $page->onEachSide(1)->links('pagination.espace') }}
    @endif
</x-layouts.espace>
