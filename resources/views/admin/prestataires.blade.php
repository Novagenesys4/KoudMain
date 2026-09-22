@use('App\Support\Format')
<x-layouts.espace titre="Prestataires" :recherche="false">
    <div data-region="prestataires" data-region-evenements="admin">
    <x-espace.entete etiquette="Administration" titre="Les" suite="prestataires"
                     intro="Validez les nouveaux profils : tant qu'un prestataire n'est pas validé, il ne peut pas se connecter." />

    <x-espace.panneau titre="Profils à valider" :etiquette="Format::pluriel($aValider->total(), 'profil')" data-reveal>
        @if ($aValider->isEmpty())
            <x-espace.vide icone="coche" titre="Tout est à jour" texte="Aucun prestataire n'attend de validation pour le moment." />
        @else
            @include('admin._a-valider', ['liste' => $aValider])
            {{ $aValider->links('pagination.espace') }}
        @endif
    </x-espace.panneau>

    <x-espace.panneau class="mt-6" titre="Prestataires validés" :etiquette="Format::pluriel($valides->total(), 'prestataire')" data-reveal>
        @if ($valides->isEmpty())
            <x-espace.vide icone="utilisateur-valide" titre="Aucun prestataire validé" texte="Les prestataires validés apparaissent ici." />
        @else
            <table class="tableau">
                <thead>
                    <tr><th scope="col">Prestataire</th><th scope="col">Contact</th><th scope="col">Quartier</th><th scope="col">Prestations</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($valides as $u)
                        <tr>
                            <td data-label="Prestataire">
                                <span class="flex items-center gap-3 text-left">
                                    <x-avatar :utilisateur="$u" taille="size-9 text-xs" />
                                    <a href="{{ route('prestataires.voir', $u->id) }}" class="lien truncate font-medium">{{ $u->nom_complet }}</a>
                                </span>
                            </td>
                            <td data-label="Contact" class="text-soft"><span class="block break-all">{{ $u->email }}</span><span class="block">{{ $u->telephone }}</span></td>
                            <td data-label="Quartier" class="text-soft">{{ $u->quartier->nom }}</td>
                            <td data-label="Prestations" class="tabular-nums">{{ $u->prestations_count }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.prestataires.suspendre', $u->id) }}" class="flex justify-end"
                                      data-confirmer="Suspendre {{ $u->nom_complet }} ? Il n'aura plus accès à la plateforme et ses offres quitteront le catalogue. « Valider » le rétablira." data-confirmer-bouton="Oui, suspendre">
                                    @csrf
                                    <button type="submit" class="btn btn-petit"><x-icone nom="interdit" taille="size-3.5" /><span>Suspendre</span></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $valides->links('pagination.espace') }}
        @endif
    </x-espace.panneau>
    </div>
</x-layouts.espace>
