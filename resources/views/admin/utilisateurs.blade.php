@use('App\Support\Format')
<x-layouts.espace titre="Utilisateurs" :recherche="false">
    <x-espace.entete etiquette="Administration" titre="Les" suite="utilisateurs" intro="Tous les comptes de la plateforme." />

    <form method="GET" action="{{ route('admin.utilisateurs') }}" role="search" class="mb-5 flex flex-wrap items-end gap-3">
        <div class="champ min-w-56 flex-1">
            <label for="q" class="sr-only">Rechercher un utilisateur</label>
            <div class="champ-saisie"><input id="q" name="q" type="search" maxlength="100" value="{{ $q }}" placeholder="Nom, e-mail ou téléphone" autocomplete="off"></div>
        </div>
        <div class="champ w-48">
            <label for="role" class="sr-only">Rôle</label>
            <div class="champ-saisie">
                <select id="role" name="role">
                    <option value="">Tous les rôles</option>
                    <option value="client" @selected($role === 'client')>Clients</option>
                    <option value="prestataire" @selected($role === 'prestataire')>Prestataires</option>
                    <option value="admin" @selected($role === 'admin')>Administrateurs</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-plein"><x-icone nom="recherche" taille="size-4" /><span>Rechercher</span></button>
        @if ($q !== '' || $role !== '')
            <a href="{{ route('admin.utilisateurs') }}" class="lien text-sm">Effacer</a>
        @endif
    </form>

    <x-espace.panneau titre="Comptes" :etiquette="Format::pluriel($utilisateurs->total(), 'compte')" data-reveal>
        @if ($utilisateurs->isEmpty())
            <x-espace.vide icone="recherche" titre="Aucun compte ne correspond" texte="Essayez un autre nom, e-mail ou numéro." />
        @else
            <table class="tableau">
                <thead>
                    <tr><th scope="col">Utilisateur</th><th scope="col">Rôle</th><th scope="col">Quartier</th><th scope="col">Inscrit le</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($utilisateurs as $u)
                        <tr>
                            <td data-label="Utilisateur">
                                <span class="flex items-center gap-3 text-left">
                                    <x-avatar :utilisateur="$u" taille="size-9 text-xs" />
                                    <span class="min-w-0"><span class="block truncate font-medium">{{ $u->nom_complet }}</span><span class="block truncate text-faint">{{ $u->email }}</span></span>
                                </span>
                            </td>
                            <td data-label="Rôle">
                                @if ($u->est_admin)
                                    <span class="statut">Administrateur</span>
                                @elseif ($u->est_prestataire)
                                    <span class="statut {{ $u->est_valide ? 'statut-ok' : 'statut-attente' }}">Prestataire{{ $u->est_valide ? '' : ' · à valider' }}</span>
                                @else
                                    <span class="statut">Client</span>
                                @endif
                            </td>
                            <td data-label="Quartier" class="text-soft">{{ $u->quartier->nom }}</td>
                            <td data-label="Inscrit le" class="whitespace-nowrap text-soft">{{ $u->created_at->translatedFormat('j M Y') }}</td>
                            <td>
                                @unless ($u->est_admin || $u->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.utilisateurs.supprimer', $u->id) }}" class="flex justify-end"
                                          data-confirmer="Supprimer définitivement le compte de {{ $u->nom_complet }} ? Tout ce qui lui appartient est effacé : prestations, commandes, messages, avis, wallet, paiements et retraits.{{ $u->est_prestataire ? ' L\'argent bloqué de ses clients leur est remboursé.' : '' }} Cette action est irréversible." data-confirmer-bouton="Oui, supprimer">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-petit btn-danger" aria-label="Supprimer {{ $u->nom_complet }}"><x-icone nom="corbeille" taille="size-3.5" /><span>Supprimer</span></button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $utilisateurs->links('pagination.espace') }}
        @endif
    </x-espace.panneau>
</x-layouts.espace>
