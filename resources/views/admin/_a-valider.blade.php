{{-- Liste des prestataires qui attendent une validation. $liste : collection ou paginator de User (quartier et avatar chargés).
     Une liste (et non un tableau) : elle tient dans toutes les largeurs, sans défilement horizontal. --}}
<ul role="list" class="divide-y divide-line">
    @foreach ($liste as $u)
        <li class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
            <x-avatar :utilisateur="$u" taille="size-10 text-sm" />
            <div class="min-w-0 flex-1 basis-56">
                <p class="truncate font-medium">{{ $u->nom_complet }}</p>
                <p class="truncate text-sm text-soft">{{ $u->email }} · {{ $u->telephone }}</p>
                <p class="truncate text-sm text-faint">{{ $u->quartier->nom }} · inscrit le {{ $u->created_at->translatedFormat('j M Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.prestataires.valider', $u->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-petit btn-plein" aria-label="Valider {{ $u->nom_complet }}"><x-icone nom="coche" taille="size-3.5" /><span>Valider</span></button>
                </form>
                <form method="POST" action="{{ route('admin.utilisateurs.supprimer', $u->id) }}"
                      data-confirmer="Refuser {{ $u->nom_complet }} ? Son compte sera supprimé définitivement." data-confirmer-bouton="Oui, refuser">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-petit btn-danger" aria-label="Refuser {{ $u->nom_complet }}"><x-icone nom="fermer" taille="size-3.5" /><span>Refuser</span></button>
                </form>
            </div>
        </li>
    @endforeach
</ul>
