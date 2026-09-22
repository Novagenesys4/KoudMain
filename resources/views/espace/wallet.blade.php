@use('App\Support\Format')
@php
    $maxBarre = max(1, max(array_column($mois, 'montant')));
    $aDesCartes = count($cartes) > 0;
    $carteInitiale = $aDesCartes ? (collect($cartes)->firstWhere('id', $carteChoisie)['id'] ?? collect($cartes)->firstWhere('principale', true)['id'] ?? $cartes[0]['id']) : null;
    $carteParDefaut = $aDesCartes ? (collect($cartes)->firstWhere(fn ($c) => $c['id'] === $carteInitiale && ! $c['gelee'])['id'] ?? collect($cartes)->firstWhere('gelee', false)['id'] ?? null) : null;
    $totalMois = array_sum(array_column($mois, 'montant'));
    $ouvrir = session('ouvrir');
    $libellesType = ['credit' => 'Entrée', 'debit' => 'Sortie', 'retrait' => 'Retrait'];
    $carteAtteinte = count($cartes) >= $maxCartes;
    $intro = 'Votre argent, vos cartes, votre tranquillité.';
@endphp
<x-layouts.espace titre="Mon wallet" :recherche="false">
    <x-espace.entete etiquette="Finance" titre="Mon" suite="wallet" :intro="$intro" />

    @if ($peutRecharger && $simulation)
        <p class="message mb-6" role="status"><strong>Mode simulation</strong> : les recharges sont fictives, aucun argent réel n'est débité ni encaissé. Le paiement Mobile Money réel s'active avec la clé de l'agrégateur (voir le README).</p>
    @elseif ($peutRecharger && ! $rechargeActive)
        <p class="message mb-6" role="status">La recharge par Mobile Money n'est pas encore activée. Elle le sera dès que le service de paiement sera relié.</p>
    @endif

    @foreach ($enAttente as $paiement)
        <p class="message mb-6" role="status">
            Recharge de {{ Format::fcfa($paiement->montant) }} ({{ $paiement->methode }}) en attente de confirmation.
            @if ($paiement->url_paiement)<a href="{{ route('paiements.continuer', $paiement->reference) }}" class="lien">Reprendre le paiement</a>@endif
        </p>
    @endforeach

    {{-- ------------------------------------------------------------ Solde --}}
    <section class="solde-banniere" aria-label="Solde du wallet" data-reveal data-region="soldes" data-region-evenements="commande retrait notification">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <p class="solde-etiquette">Solde disponible</p>
                <button type="button" class="solde-oeil" data-masquer-solde aria-pressed="false" aria-label="Masquer le solde">
                    <x-icone nom="oeil" taille="size-[1.125rem]" class="solde-oeil-ouvert" />
                    <x-icone nom="oeil-barre" taille="size-[1.125rem]" class="solde-oeil-ferme" />
                </button>
            </div>
            <div class="solde-montant" data-secret>
                <x-island nom="SoldeAnime" class="inline-block" :donnees="['valeur' => $solde, 'depart' => (float) ($mouvement['avant'] ?? $solde)]">{{ Format::montant($solde) }}</x-island>
                <span class="solde-devise">FCFA</span>
            </div>
            <p class="solde-note" data-secret>
                <x-icone nom="cadenas" taille="size-3.5" />
                {{ $role === 'client' ? 'En séquestre' : 'À recevoir' }} : {{ Format::fcfa($bloque) }}
                <span aria-hidden="true">·</span>
                {{ $role === 'client' ? 'bloqué dans vos commandes en cours' : 'libéré à la fin des prestations' }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if ($peutRecharger)
                <button type="button" class="solde-bouton solde-bouton-plein" data-dialogue-ouvrir="recharge" @disabled(! $rechargeActive)><x-icone nom="plus" taille="size-4" /><span>Alimenter</span></button>
            @endif
            @if ($peutRetirer)
                <button type="button" class="solde-bouton {{ $peutRecharger ? '' : 'solde-bouton-plein' }}" data-dialogue-ouvrir="retrait" @disabled($solde < $retraitMin)><x-icone nom="fleche-haut-droite" taille="size-4" /><span>Retirer</span></button>
            @elseif ($peutRecharger)
                {{-- Un client n'a pas de retrait : l'argent du wallet sert à payer ses commandes. --}}
                <a href="{{ route('client.catalogue') }}" class="solde-bouton"><x-icone nom="recherche" taille="size-4" /><span>Trouver un service</span></a>
            @endif
        </div>
    </section>

    @if ($mouvement)
        <x-island nom="SuiviMouvement" :donnees="$mouvement + ['minimumRetrait' => $retraitMin]" class="mt-6">
            <p class="message" role="status">{{ $mouvement['type'] === 'recharge' ? 'Recharge' : 'Retrait' }} de {{ Format::fcfa($mouvement['montant']) }} enregistré.</p>
        </x-island>
    @endif

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,21rem)]">
        <div class="grid min-w-0 gap-6">
            {{-- ------------------------------------------------------------ Cartes --}}
            <x-espace.panneau titre="Vos cartes" etiquette="Moyens de paiement" data-reveal>
                <x-slot:actions>
                    <button type="button" class="btn btn-petit" data-dialogue-ouvrir="carte" @disabled($carteAtteinte) @if ($carteAtteinte) title="Maximum {{ $maxCartes }} cartes : supprimez-en une d'abord." @endif><x-icone nom="plus" taille="size-4" /><span>Ajouter une carte</span></button>
                </x-slot:actions>

                <div class="p-5 sm:p-6" data-cartes-zone data-carte-initiale="{{ $carteInitiale }}">
                    @if ($aDesCartes)
                        <x-island nom="CartesWallet" :donnees="['cartes' => $cartes, 'initiale' => $carteInitiale]">
                            {{-- Sans JavaScript : toutes les cartes, l'une à côté de l'autre. --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach ($cartes as $c)
                                    <x-carte :carte="$c" />
                                @endforeach
                            </div>
                        </x-island>

                        <div class="mt-5 border-t border-line pt-4">
                            @foreach ($cartes as $c)
                                <div data-carte-actions="{{ $c['id'] }}" @if ($c['id'] !== $carteInitiale) hidden @endif>
                                    <p class="text-sm text-faint">Adresse de facturation : <span class="text-soft">{{ $c['adresse'] ?: 'non renseignée' }}</span></p>
                                    <form method="POST" action="{{ $c['urlSupprimer'] }}" class="mt-3" data-confirmer="Supprimer la carte « {{ $c['libelle'] }} » ? Votre historique est conservé, seule la carte disparaît." data-confirmer-bouton="Supprimer la carte">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-petit btn-danger"><x-icone nom="corbeille" taille="size-4" /><span>Supprimer cette carte</span></button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="carte-vide">
                            <span class="carte-vide-icone"><x-icone nom="carte-bancaire" taille="size-6" /></span>
                            <h3 class="text-lg">Aucune carte pour le moment</h3>
                            <p class="mt-2 max-w-sm text-sm text-soft">Ajoutez votre carte Visa, Mastercard ou American Express pour payer vos commandes et recharger votre wallet.</p>
                            <button type="button" class="btn btn-plein mt-5" data-dialogue-ouvrir="carte"><x-icone nom="plus" taille="size-4" /><span>Ajouter une carte</span></button>
                        </div>
                    @endif
                </div>
            </x-espace.panneau>

            {{-- ------------------------------------------------------------ Historique --}}
            <x-espace.panneau titre="Transactions" etiquette="Historique récent" data-reveal data-region="historique" data-region-evenements="commande retrait notification">
                <x-slot:actions>
                    <a href="{{ route($role.'.wallet.export') }}" class="btn btn-petit" download><x-icone nom="fleche-bas" taille="size-4" /><span>Exporter</span></a>
                </x-slot:actions>
                <form method="GET" action="{{ route($role.'.wallet') }}" role="search" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4" data-get-propre>
                    <div class="champ min-w-40 flex-1">
                        <label for="q" class="sr-only">Rechercher dans l'historique</label>
                        <div class="champ-saisie"><input id="q" name="q" type="search" maxlength="100" value="{{ $q }}" placeholder="Rechercher (commande, recharge…)" autocomplete="off"></div>
                    </div>
                    <div class="champ w-36">
                        <label for="type" class="sr-only">Type d'opération</label>
                        <div class="champ-saisie">
                            <select id="type" name="type" data-auto-submit>
                                <option value="">Toutes</option>
                                @foreach ($libellesType as $valeur => $libelle)
                                    <option value="{{ $valeur }}" @selected($type === $valeur)>{{ $libelle }}s</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-petit"><x-icone nom="recherche" taille="size-4" /><span>Filtrer</span></button>
                    @if ($q !== '' || $type)
                        <a href="{{ route($role.'.wallet') }}" class="lien text-sm">Effacer</a>
                    @endif
                </form>

                @if ($operations->isEmpty())
                    <x-espace.vide icone="portefeuille" :titre="($q !== '' || $type) ? 'Aucune opération ne correspond' : 'Aucune opération pour le moment'"
                                   :texte="($q !== '' || $type) ? 'Essayez un autre mot ou effacez le filtre.' : ($role === 'client' ? 'Rechargez votre wallet, puis commandez : chaque mouvement apparaîtra ici.' : 'Le paiement de vos commandes terminées apparaîtra ici.')" />
                @else
                    <ul role="list" class="divide-y divide-line">
                        @foreach ($operations as $operation)
                            @php($entree = $operation->type === 'credit')
                            <li class="flex items-center gap-3.5 px-5 py-3.5">
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-xl', 'bg-teal-tint text-teal' => $entree, 'bg-amber-tint text-accent' => ! $entree])>
                                    <x-icone :nom="$entree ? 'fleche-bas' : 'fleche-haut-droite'" taille="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium [overflow-wrap:anywhere]">
                                        @if ($operation->commande_id)
                                            <a href="{{ route($role.'.commandes.voir', $operation->commande_id) }}" class="hover:text-accent">{{ $operation->libelle }}</a>
                                        @else
                                            {{ $operation->libelle }}
                                        @endif
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-faint">
                                        <span>{{ $operation->created_at->translatedFormat('j M Y, H:i') }}</span>
                                        @if ($operation->carte)<span class="via-carte"><x-icone nom="carte-bancaire" taille="size-3" />{{ $operation->carte->libelle }} · {{ substr($operation->carte->numero_masque, -4) }}</span>@endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p @class(['whitespace-nowrap text-sm font-semibold tabular-nums', 'text-teal' => $entree])>
                                        <span class="sr-only">{{ $entree ? 'Entrée' : 'Sortie' }} de</span>{{ $entree ? '+' : '−' }}{{ Format::montant($operation->montant) }} <span class="font-normal text-faint">FCFA</span>
                                    </p>
                                    <p class="mt-0.5 whitespace-nowrap text-xs text-faint tabular-nums">solde : {{ Format::montant($operation->solde_apres) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    {{ $operations->links('pagination.espace') }}
                @endif
            </x-espace.panneau>
        </div>

        {{-- ------------------------------------------------------------ Colonne de droite --}}
        <aside class="grid min-w-0 gap-6" aria-label="Résumé du wallet">
            <x-espace.panneau titre="Ce mois-ci" etiquette="Résumé" data-reveal data-region="mois" data-region-evenements="commande retrait notification">
                <dl class="divide-y divide-line px-5">
                    <div class="flex items-center justify-between gap-3 py-3.5">
                        <dt class="text-sm text-soft">Entrées</dt>
                        <dd class="text-sm font-semibold tabular-nums text-teal">+ {{ Format::fcfa($ceMois['entrees']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-3.5">
                        <dt class="text-sm text-soft">Sorties</dt>
                        <dd class="text-sm font-semibold tabular-nums">− {{ Format::fcfa($ceMois['sorties']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-3.5">
                        <dt class="text-sm font-medium">Variation nette</dt>
                        <dd @class(['text-sm font-semibold tabular-nums', 'text-teal' => $ceMois['net'] > 0])>{{ $ceMois['net'] > 0 ? '+ ' : ($ceMois['net'] < 0 ? '− ' : '') }}{{ Format::fcfa(abs($ceMois['net'])) }}</dd>
                    </div>
                </dl>
            </x-espace.panneau>

            <x-espace.panneau titre="Entrées sur 6 mois" etiquette="Tendance" data-reveal style="--i: 1">
                <div class="px-5 pb-2 pt-5">
                    <div class="barres" role="img" aria-label="Entrées par mois : {{ collect($mois)->map(fn ($m) => $m['libelle'].' '.Format::montant($m['montant']))->implode(', ') }}">
                        @foreach ($mois as $m)
                            <div @if ($loop->last) data-actif @endif title="{{ $m['libelle'] }} : {{ Format::fcfa($m['montant']) }}">
                                <i style="--h: {{ $m['montant'] > 0 ? max(4, round($m['montant'] / $maxBarre * 100)) : 0 }}%"></i>
                                <span>{{ $m['libelle'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-between gap-3 px-5 pb-5 pt-2 text-sm">
                    <span class="inline-flex items-center gap-2 text-soft"><span class="size-2 rounded-full bg-teal" aria-hidden="true"></span>Crédits entrants</span>
                    <span class="tabular-nums text-faint">+ {{ Format::fcfa($totalMois) }} au total</span>
                </div>
            </x-espace.panneau>

            @if ($peutRetirer)
                <x-espace.panneau titre="Mes retraits" etiquette="Derniers retraits" data-reveal style="--i: 2">
                    @if ($retraits->isEmpty())
                        <x-espace.vide titre="Aucun retrait" texte="Retirez votre solde vers Wave, Orange Money ou MTN quand vous voulez (minimum {{ Format::fcfa($retraitMin) }})." />
                    @else
                        <ul role="list" class="divide-y divide-line">
                            @foreach ($retraits as $retrait)
                                <li class="px-5 py-3.5">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-medium tabular-nums">{{ Format::fcfa($retrait->montant) }}</p>
                                        <span @class(['statut', 'statut-ok' => $retrait->statut === 'effectue', 'statut-attente' => $retrait->statut === 'en_attente', 'statut-danger' => $retrait->statut === 'refuse'])>
                                            {{ ['en_attente' => 'En attente', 'effectue' => 'Effectué', 'refuse' => 'Refusé'][$retrait->statut] }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-sm text-faint">{{ $retrait->methode }} · {{ $retrait->created_at->translatedFormat('j M Y') }}</p>
                                    @if ($retrait->motif_refus)<p class="mt-1 text-sm text-danger">{{ $retrait->motif_refus }}</p>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-espace.panneau>
            @endif

            <div class="carte-confiance" data-reveal style="--i: 3">
                <span class="carte-confiance-icone"><x-icone nom="bouclier" /></span>
                <h3 class="mt-4 text-xl">La confiance, <em>ça se construit.</em></h3>
                <p class="mt-2 text-sm">Chaque paiement par wallet est mis en séquestre : le prestataire n'est payé qu'une fois la prestation terminée et confirmée. Vos cartes, elles, ne conservent jamais votre numéro complet ni votre code de sécurité.</p>
                <a href="{{ route('accueil') }}#etapes" class="lien mt-4 inline-flex items-center gap-1.5 text-sm font-medium">Comment ça marche<x-icone nom="fleche-droite" taille="size-3.5" /></a>
            </div>
        </aside>
    </div>

    {{-- ---------------------------------------------------------------- Recharger --}}
    @if ($peutRecharger)
        <dialog id="recharge" class="dialogue dialogue-large" data-boite aria-labelledby="recharge-titre" @if ($ouvrir === 'recharge') data-ouvert-auto @endif>
            <form method="POST" action="{{ route('client.wallet.recharger') }}">
                @csrf
                <div class="dialogue-tete">
                    <div>
                        <h2 id="recharge-titre">Recharger mon wallet</h2>
                        <p>Minimum {{ Format::fcfa($rechargeMin) }}. Le solde est mis à jour dès que l'opérateur confirme.</p>
                    </div>
                    <button type="button" class="dialogue-fermer" data-dialogue-fermer aria-label="Fermer"><x-icone nom="fermer" taille="size-4" /></button>
                </div>
                <x-dialogue.alerte nom="recharge" :champs="['montant', 'methode', 'telephone', 'carte_id']" titre="La recharge n'a pas été faite." />

                @if ($simulation)
                    <p class="message mt-4">Mode simulation : aucun paiement réel n'est effectué.</p>
                @endif

                <div class="mt-5 grid gap-5">
                    <div class="champ">
                        <label for="recharge-montant">Montant (FCFA)</label>
                        <div class="champ-saisie"><input id="recharge-montant" name="montant" type="number" inputmode="numeric" min="{{ $rechargeMin }}" step="5" value="{{ old('montant') }}" required placeholder="Ex. 10000"></div>
                        <div class="montants-rapides">
                            @foreach ($montantsRapides as $rapide)
                                <button type="button" data-montant="{{ $rapide }}">{{ Format::montant($rapide) }}</button>
                            @endforeach
                        </div>
                    </div>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">Payer avec</legend>
                        <div class="choix">
                            @foreach ($methodesRecharge as $methode)
                                <label @if ($methode === 'Carte bancaire' && ! $aDesCartes) title="Ajoutez d'abord une carte" @endif><input type="radio" name="methode" value="{{ $methode }}" @checked(old('methode', $methodesRecharge[0]) === $methode) @disabled($methode === 'Carte bancaire' && ! $aDesCartes) required><span>{{ $methode }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>

                    {{-- Visible seulement si « Carte bancaire » est choisi. --}}
                    @if ($aDesCartes)
                        <div class="champ" data-si-methode="Carte bancaire" hidden>
                            <label for="recharge-carte">Carte utilisée</label>
                            <div class="champ-saisie">
                                <select id="recharge-carte" name="carte_id" data-carte-champ>
                                    @foreach ($cartes as $c)
                                        <option value="{{ $c['id'] }}" @selected(old('carte_id', $carteParDefaut) == $c['id']) @disabled($c['gelee'])>{{ $c['libelle'] }} · {{ $c['fin'] }}{{ $c['gelee'] ? ' (gelée)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="champ" data-sauf-methode="Carte bancaire">
                        <label for="recharge-tel">Numéro à débiter <span class="font-normal text-faint">(Mobile Money)</span></label>
                        <div class="champ-saisie"><input id="recharge-tel" name="telephone" type="tel" inputmode="tel" autocomplete="tel-national" value="{{ old('telephone', auth()->user()->telephone) }}" placeholder="07 01 02 03 04"></div>
                    </div>
                </div>

                <div class="dialogue-actions">
                    <button type="button" class="btn btn-petit" data-dialogue-fermer><span>Annuler</span></button>
                    <button type="submit" class="btn btn-petit btn-plein" data-chargement="Paiement…"><span data-libelle>Recharger</span></button>
                </div>
            </form>
        </dialog>
    @endif

    {{-- ---------------------------------------------------------------- Retirer --}}
    @if ($peutRetirer)
        <dialog id="retrait" class="dialogue dialogue-large" data-boite aria-labelledby="retrait-titre" @if ($ouvrir === 'retrait') data-ouvert-auto @endif>
            <form method="POST" action="{{ route('prestataire.wallet.retrait') }}">
                @csrf
                <div class="dialogue-tete">
                    <div>
                        <h2 id="retrait-titre">Retirer mon argent</h2>
                        <p>Solde disponible : {{ Format::fcfa($solde) }}. Minimum {{ Format::fcfa($retraitMin) }}. Virement sous 24 à 48 h.</p>
                    </div>
                    <button type="button" class="dialogue-fermer" data-dialogue-fermer aria-label="Fermer"><x-icone nom="fermer" taille="size-4" /></button>
                </div>
                <x-dialogue.alerte nom="retrait" :champs="['montant', 'methode', 'destination', 'carte_id']" titre="Le retrait n'a pas été demandé." />

                <div class="mt-5 grid gap-5">
                    <div class="champ">
                        <label for="retrait-montant">Montant (FCFA)</label>
                        <div class="champ-saisie"><input id="retrait-montant" name="montant" type="number" inputmode="numeric" placeholder="Ex. 5000" min="{{ $retraitMin }}" max="{{ (int) floor($solde) }}" value="{{ old('montant') }}" required></div>
                        <div class="montants-rapides">
                            @foreach (array_filter($montantsRapides, fn ($m) => $m <= $solde && $m >= $retraitMin) as $rapide)
                                <button type="button" data-montant="{{ $rapide }}">{{ Format::montant($rapide) }}</button>
                            @endforeach
                            <button type="button" data-montant="{{ (int) floor($solde) }}">Tout</button>
                        </div>
                    </div>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">Recevoir sur</legend>
                        <div class="choix">
                            @foreach ($methodesRetrait as $methode)
                                <label><input type="radio" name="methode" value="{{ $methode }}" @checked(old('methode', $methodesRetrait[0]) === $methode) required><span>{{ $methode }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="champ">
                        <label for="retrait-destination">Numéro Mobile Money ou RIB</label>
                        <div class="champ-saisie"><input id="retrait-destination" name="destination" type="text" maxlength="60" value="{{ old('destination', auth()->user()->telephone) }}" required autocomplete="off"></div>
                        <p class="champ-aide">Vérifiez bien : un virement vers un mauvais numéro ne se récupère pas.</p>
                    </div>
                </div>

                <div class="dialogue-actions">
                    <button type="button" class="btn btn-petit" data-dialogue-fermer><span>Annuler</span></button>
                    <button type="submit" class="btn btn-petit btn-plein" data-chargement="Envoi…"><span data-libelle>Demander le retrait</span></button>
                </div>
            </form>
        </dialog>
    @endif

    {{-- ---------------------------------------------------------------- Ajouter une carte --}}
    <dialog id="carte" class="dialogue dialogue-large" data-boite aria-labelledby="carte-titre" @if ($ouvrir === 'carte' || $errors->hasAny(['numero_carte', 'expiration', 'cvv', 'prenom', 'nom', 'adresse', 'ville', 'pays', 'libelle', 'couleur'])) data-ouvert-auto @endif>
        <form method="POST" action="{{ route($role.'.wallet.cartes.creer') }}" autocomplete="on" novalidate data-saisie-carte data-annees-max="{{ (int) config('koudmain.cartes.expiration_max_annees', 10) }}">
            @csrf
            <div class="dialogue-tete">
                <div>
                    <h2 id="carte-titre">Ajouter une carte</h2>
                    <p>Visa, Mastercard ou American Express. Le numéro complet et le code de sécurité ne sont jamais enregistrés. Vous avez {{ count($cartes) }} carte{{ count($cartes) > 1 ? 's' : '' }} sur {{ $maxCartes }}.</p>
                </div>
                <button type="button" class="dialogue-fermer" data-dialogue-fermer aria-label="Fermer"><x-icone nom="fermer" taille="size-4" /></button>
            </div>
            <x-dialogue.alerte nom="carte" :champs="['numero_carte', 'expiration', 'cvv', 'prenom', 'nom', 'adresse', 'ville', 'pays', 'libelle', 'couleur']" titre="La carte n'a pas été ajoutée." />

            {{-- Aperçu en direct : la carte se remplit pendant la saisie. --}}
            <div class="carte-apercu" aria-hidden="true">
                <div class="carte carte-emerald" data-apercu>
                    <div class="carte-haut">
                        <div>
                            <p class="carte-nom" data-apercu-nom>Nouvelle carte</p>
                            <p class="carte-sous-nom">Carte bancaire</p>
                        </div>
                        <span class="carte-reseau" data-apercu-reseau data-reseau=""></span>
                    </div>
                    <span class="carte-puce"></span>
                    <p class="carte-numero" data-apercu-numero>•••• •••• •••• ••••</p>
                    <div class="carte-bas">
                        <div>
                            <span class="carte-legende">Titulaire</span>
                            <span class="carte-valeur" data-apercu-titulaire>Prénom Nom</span>
                        </div>
                        <div>
                            <span class="carte-legende">Expire</span>
                            <span class="carte-valeur" data-apercu-expire>MM/AA</span>
                        </div>
                        <span class="carte-marque">koudmain</span>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid items-start gap-5 sm:grid-cols-2">
                <div class="champ sm:col-span-2">
                    <label for="carte-numero">Numéro de carte</label>
                    <div class="champ-saisie">
                        <input id="carte-numero" name="numero_carte" @error('numero_carte') aria-invalid="true" @enderror type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="1234 5678 9012 3456" required data-champ-numero aria-describedby="carte-numero-aide">
                    </div>
                    <p id="carte-numero-aide" class="champ-aide" data-aide-numero>16 chiffres (15 pour American Express), au recto de la carte.</p>
                    @error('numero_carte')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-expiration">Date d'expiration</label>
                    <div class="champ-saisie"><input id="carte-expiration" name="expiration" @error('expiration') aria-invalid="true" @enderror type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA" value="{{ old('expiration') }}" required data-champ-expiration></div>
                    @error('expiration')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-cvv">Code de sécurité (CVV / CVC)</label>
                    <div class="champ-saisie"><input id="carte-cvv" name="cvv" @error('cvv') aria-invalid="true" @enderror type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="•••" required data-champ-cvv aria-describedby="carte-cvv-aide"></div>
                    <p id="carte-cvv-aide" class="champ-aide" data-aide-cvv>3 chiffres au dos de la carte (4 pour American Express).</p>
                    @error('cvv')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-prenom">Prénom du titulaire</label>
                    <div class="champ-saisie"><input id="carte-prenom" name="prenom" @error('prenom') aria-invalid="true" @enderror type="text" autocomplete="cc-given-name" maxlength="40" value="{{ old('prenom') }}" required data-champ-prenom placeholder="Comme sur la carte"></div>
                    @error('prenom')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-nom">Nom du titulaire</label>
                    <div class="champ-saisie"><input id="carte-nom" name="nom" @error('nom') aria-invalid="true" @enderror type="text" autocomplete="cc-family-name" maxlength="40" value="{{ old('nom') }}" required data-champ-nom placeholder="Comme sur la carte"></div>
                    @error('nom')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ sm:col-span-2">
                    <label for="carte-adresse">Adresse de facturation</label>
                    <div class="champ-saisie"><input id="carte-adresse" name="adresse" @error('adresse') aria-invalid="true" @enderror type="text" autocomplete="billing street-address" maxlength="120" value="{{ old('adresse') }}" required placeholder="Rue, quartier, numéro de porte"></div>
                    @error('adresse')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-ville">Ville</label>
                    <div class="champ-saisie"><input id="carte-ville" name="ville" @error('ville') aria-invalid="true" @enderror type="text" autocomplete="billing address-level2" maxlength="60" value="{{ old('ville') }}" required></div>
                    @error('ville')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ">
                    <label for="carte-pays">Pays</label>
                    <div class="champ-saisie"><input id="carte-pays" name="pays" @error('pays') aria-invalid="true" @enderror type="text" autocomplete="billing country-name" maxlength="60" value="{{ old('pays', "Côte d'Ivoire") }}" required></div>
                    @error('pays')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="champ sm:col-span-2">
                    <label for="carte-libelle">Nom de la carte <span class="font-normal text-faint">(facultatif)</span></label>
                    <div class="champ-saisie"><input id="carte-libelle" name="libelle" @error('libelle') aria-invalid="true" @enderror type="text" minlength="2" maxlength="30" value="{{ old('libelle') }}" placeholder="Ex. Courses, Réserve… (sinon : réseau et 4 derniers chiffres)" autocomplete="off" data-champ-libelle></div>
                    @error('libelle')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </div>

                <fieldset class="sm:col-span-2">
                    <legend class="mb-2 text-sm font-medium">Couleur de la carte</legend>
                    <div class="couleurs">
                        @foreach ($couleursCartes as $valeur => $libelle)
                            <label>
                                <input type="radio" name="couleur" value="{{ $valeur }}" @checked(old('couleur', 'emerald') === $valeur) required data-champ-couleur>
                                <i class="carte-{{ $valeur }}"></i>
                                <span>{{ $libelle }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('couleur')<p class="champ-erreur" role="alert">{{ $message }}</p>@enderror
                </fieldset>
            </div>

            <p class="mt-5 flex items-start gap-2.5 rounded-xl bg-deep px-4 py-3 text-sm text-soft">
                <x-icone nom="bouclier" taille="size-[1.125rem]" class="mt-0.5 text-teal" />
                <span>Après vérification, KoudMain ne garde que le réseau, les 4 derniers chiffres, l'expiration, le titulaire et l'adresse. Le numéro complet et le code de sécurité sont oubliés.</span>
            </p>

            <div class="dialogue-actions">
                <button type="button" class="btn btn-petit" data-dialogue-fermer><span>Annuler</span></button>
                <button type="submit" class="btn btn-petit btn-plein" data-chargement="Vérification…"><span data-libelle>Ajouter la carte</span></button>
            </div>
        </form>
    </dialog>
</x-layouts.espace>
