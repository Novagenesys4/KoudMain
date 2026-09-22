@use('App\Support\Format')
@php
    $libelles = ['en_attente' => 'En attente', 'effectue' => 'Effectués', 'refuse' => 'Refusés'];
    $aTraiter = (int) ($compteurs['en_attente']->n ?? 0);
    $montantATraiter = (float) ($compteurs['en_attente']->total ?? 0);
@endphp
<x-layouts.espace titre="Retraits" :recherche="false">
    <div data-region="retraits" data-region-evenements="retrait admin">
    <x-espace.entete etiquette="Administration" titre="Les" suite="retraits"
                     intro="Les demandes de retrait des prestataires. Faites le virement vers le numéro indiqué, puis confirmez ici. Le montant a déjà quitté leur solde : un refus le leur rend.">
    </x-espace.entete>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-espace.stat data-reveal libelle="À traiter" :valeur="$aTraiter" icone="horloge" :note="Format::fcfa($montantATraiter).' à virer'" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Effectués" :valeur="(int) ($compteurs['effectue']->n ?? 0)" icone="coche" :note="Format::fcfa($compteurs['effectue']->total ?? 0).' versés'" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Refusés" :valeur="(int) ($compteurs['refuse']->n ?? 0)" icone="interdit" note="Argent rendu aux prestataires" />
    </div>

    <nav class="mb-5 flex flex-wrap gap-2" aria-label="Filtrer par statut">
        <a href="{{ route('admin.retraits') }}" @class(['puce', 'bg-ink text-paper' => $statut === null]) @if ($statut === null) aria-current="page" @endif>Tous</a>
        @foreach ($libelles as $valeur => $libelle)
            <a href="{{ route('admin.retraits', ['statut' => $valeur]) }}" @class(['puce', 'bg-ink text-paper' => $statut === $valeur]) @if ($statut === $valeur) aria-current="page" @endif>{{ $libelle }}</a>
        @endforeach
    </nav>

    <x-espace.panneau titre="Demandes" :etiquette="Format::pluriel($retraits->total(), 'demande')" data-reveal>
        @if ($retraits->isEmpty())
            <x-espace.vide icone="portefeuille" titre="Aucune demande de retrait" texte="Les demandes des prestataires apparaîtront ici." />
        @else
            <ul role="list" class="divide-y divide-line">
                @foreach ($retraits as $retrait)
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                            <div class="min-w-0 flex-1 basis-56">
                                <p class="font-medium">{{ $retrait->user->nom_complet }}</p>
                                <p class="text-sm text-faint">{{ $retrait->methode }} · <span class="tabular-nums">{{ $retrait->destination }}</span> · {{ $retrait->created_at->translatedFormat('j M Y, H:i') }}</p>
                            </div>
                            <span class="text-base font-semibold tabular-nums">{{ Format::fcfa($retrait->montant) }}</span>
                            <span @class(['statut', 'statut-ok' => $retrait->statut === 'effectue', 'statut-attente' => $retrait->statut === 'en_attente', 'statut-danger' => $retrait->statut === 'refuse'])>{{ ['en_attente' => 'En attente', 'effectue' => 'Effectué', 'refuse' => 'Refusé'][$retrait->statut] }}</span>
                        </div>

                        @if ($retrait->statut === 'en_attente')
                            <div class="mt-3 flex flex-wrap items-start gap-3">
                                <form method="POST" action="{{ route('admin.retraits.confirmer', $retrait) }}"
                                      data-confirmer="Avez-vous bien viré {{ Format::fcfa($retrait->montant) }} vers {{ $retrait->destination }} ({{ $retrait->methode }}) ?" data-confirmer-bouton="Oui, c'est fait" data-confirmer-ton="neutre">
                                    @csrf
                                    <button type="submit" class="btn btn-petit btn-plein" data-chargement="Un instant…"><span data-libelle>Virement effectué</span></button>
                                </form>
                                <details class="motif">
                                    <summary class="btn btn-petit btn-danger"><span>Refuser</span></summary>
                                    <form method="POST" action="{{ route('admin.retraits.refuser', $retrait) }}">
                                        @csrf
                                        <div class="champ">
                                            <label for="motif-{{ $retrait->id }}">Motif du refus (visible par le prestataire)</label>
                                            <div class="champ-saisie"><input id="motif-{{ $retrait->id }}" name="motif" type="text" maxlength="300" required placeholder="Ex. numéro incorrect"></div>
                                        </div>
                                        <div><button type="submit" class="btn btn-petit btn-danger" data-chargement="Un instant…"><span data-libelle>Refuser et rendre l'argent</span></button></div>
                                    </form>
                                </details>
                            </div>
                        @elseif ($retrait->statut === 'refuse' && $retrait->motif_refus)
                            <p class="mt-2 text-sm text-danger">Motif : {{ $retrait->motif_refus }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{ $retraits->links('pagination.espace') }}
        @endif
    </x-espace.panneau>
    </div>
</x-layouts.espace>
