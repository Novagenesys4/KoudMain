@php
    // Les icônes de notification sont choisies par le serveur ; on ne garde que celles qui existent.
    $icone = fn (string $nom) => \App\Support\Icones::existe($nom) ? $nom : 'cloche';
@endphp
<x-layouts.espace titre="Notifications" :recherche="false">
    <x-espace.entete etiquette="Vos alertes" titre="Notifications" intro="Chaque étape de vos commandes, vos paiements et vos avis. Cette page se met à jour toute seule.">
        @if ($nonLues > 0)
            <form method="POST" action="{{ route('notifications.tout-lire') }}">
                @csrf
                <button type="submit" class="btn btn-petit"><x-icone nom="coche" taille="size-4" /><span>Tout marquer comme lu</span></button>
            </form>
        @endif
    </x-espace.entete>

    <div data-region="notifications">
        <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrer les notifications">
            <a href="{{ route('notifications') }}" @class(['puce', 'bg-ink text-paper' => ! $seulementNonLues]) @unless ($seulementNonLues) aria-current="page" @endunless>Toutes</a>
            <a href="{{ route('notifications', ['filtre' => 'non-lues']) }}" @class(['puce', 'bg-ink text-paper' => $seulementNonLues]) @if ($seulementNonLues) aria-current="page" @endif>Non lues <span class="tabular-nums opacity-70">{{ $nonLues }}</span></a>
        </div>

        <x-espace.panneau :titre="$seulementNonLues ? 'Non lues' : 'Toutes les notifications'" :etiquette="\App\Support\Format::pluriel($page->total(), 'notification')">
            @if ($page->isEmpty())
                <x-espace.vide icone="cloche" :titre="$seulementNonLues ? 'Tout est lu' : 'Aucune notification pour le moment'"
                               :texte="$seulementNonLues ? 'Vous n\'avez aucune notification en attente.' : 'Vous serez prévenu ici, en direct, à chaque étape de vos commandes.'" />
            @else
                <ul role="list" class="divide-y divide-line">
                    @foreach ($notifications as $n)
                        <li>
                            <form method="POST" action="{{ route('notifications.lire', $n['id']) }}">
                                @csrf
                                <button type="submit" class="flex w-full cursor-pointer items-start gap-3.5 px-5 py-4 text-left transition-colors hover:bg-deep">
                                    <span @class(['mt-0.5 grid size-10 shrink-0 place-items-center rounded-xl', 'bg-amber-tint text-accent' => ! $n['lue'], 'bg-deep text-soft' => $n['lue']])><x-icone :nom="$icone($n['icone'])" taille="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-baseline justify-between gap-3">
                                            <span @class(['text-sm', 'font-semibold' => ! $n['lue'], 'font-medium' => $n['lue']])>{{ $n['titre'] }}</span>
                                            <time class="shrink-0 text-xs text-faint" datetime="{{ $n['date'] }}">{{ \Illuminate\Support\Carbon::parse($n['date'])->diffForHumans() }}</time>
                                        </span>
                                        <span class="mt-0.5 block text-sm text-soft">{{ $n['texte'] }}</span>
                                    </span>
                                    @unless ($n['lue'])<span class="mt-2 size-2 shrink-0 rounded-full bg-accent" title="Non lue"><span class="sr-only">Non lue</span></span>@endunless
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                {{ $page->links('pagination.espace') }}
            @endif
        </x-espace.panneau>
    </div>
</x-layouts.espace>
