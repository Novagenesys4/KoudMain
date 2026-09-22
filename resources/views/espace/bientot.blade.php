<x-layouts.espace :titre="$page['titre']" :recherche="false">
    <x-espace.entete etiquette="Arrive prochainement" :titre="$page['titre']" :intro="$page['resume']" />

    <x-espace.panneau titre="Ce qui est prévu" class="max-w-2xl">
        <ul role="list" class="divide-y divide-line">
            @foreach ($page['prevu'] as $point)
                <li class="flex items-start gap-3 px-5 py-3.5">
                    <x-icone nom="horloge" taille="mt-1 size-4" class="text-faint" />
                    <span>{{ $point }}</span>
                </li>
            @endforeach
        </ul>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-deep/40 px-5 py-4">
            <span class="statut">Prévu au lot {{ $page['lot'] }}</span>
            <a href="{{ $action['href'] }}" class="btn btn-petit"><span>{{ $action['libelle'] }}</span></a>
        </div>
    </x-espace.panneau>
</x-layouts.espace>
