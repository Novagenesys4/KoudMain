@use('App\Support\Format')
@use('App\Enums\StatutCommande')
@use('App\Models\Escrow')
@php
    $ligne = $commande->prestations->first();
    $client = $commande->client;
    $prestataire = $commande->prestataire;
    $partie = $role === 'client' ? $prestataire : $client;
    $prenom = $partie->prenom;
    $accepteeOuPlus = in_array($commande->statut, [StatutCommande::Acceptee, StatutCommande::EnCours, StatutCommande::Terminee, StatutCommande::Litige], true);
    $confirmee = $commande->validee_client_at !== null;
    $physique = $commande->payeeEnPhysique();
    $mode = $commande->mode_paiement;
    $auto = $commande->terminee_at?->copy()->addDays((int) config('koudmain.finance.liberation_auto_jours'));

    // Une phrase claire sur « où en est-on », adaptée à celui qui lit.
    $situation = match (true) {
        $commande->statut === StatutCommande::EnAttente && $role === 'client' && $physique => "En attente de la réponse de $prenom. Vous le réglerez en main propre le jour de la prestation ; vous pouvez annuler tant qu'elle n'a pas commencé.",
        $commande->statut === StatutCommande::EnAttente && $role === 'prestataire' && $physique => "Nouvelle demande de $prenom, à régler en main propre. Acceptez-la pour réserver le créneau, ou annulez-la.",
        $commande->statut === StatutCommande::Terminee && ! $confirmee && $physique => $role === 'client' ? "$prenom a terminé. Confirmez la réception, ou signalez un problème. Sans réponse de votre part, la commande est confirmée automatiquement le ".$auto?->translatedFormat('j F').'.' : 'Prestation terminée. Le client doit confirmer la réception ; sans réponse de sa part, la commande est confirmée automatiquement le '.$auto?->translatedFormat('j F').'.',
        $commande->statut === StatutCommande::Terminee && $confirmee && $physique => 'Commande terminée. Le règlement se fait en main propre, en dehors de KoudMain.',
        $commande->statut === StatutCommande::Litige && $physique => 'Un problème a été signalé. Un administrateur va examiner la situation (aucun argent n\'est bloqué : le paiement se fait en main propre).',
        $commande->statut === StatutCommande::EnAttente && $role === 'client' => "En attente de la réponse de $prenom. Votre argent est bloqué en séquestre ; vous pouvez annuler tant que la prestation n'a pas commencé.",
        $commande->statut === StatutCommande::EnAttente && $role === 'prestataire' => "Nouvelle demande de $prenom. Acceptez-la pour réserver le créneau, ou annulez-la (le client est alors remboursé).",
        $commande->statut === StatutCommande::Acceptee => $role === 'client' ? "$prenom a accepté : rendez-vous le ".$commande->date_souhaitee?->translatedFormat('l j F \à H:i').'.' : 'Vous avez accepté cette commande. Démarrez la prestation le jour venu.',
        $commande->statut === StatutCommande::EnCours => 'La prestation est en cours.',
        $commande->statut === StatutCommande::Terminee && ! $confirmee && $role === 'client' => "$prenom a terminé. Confirmez la réception pour le payer, ou signalez un problème. Sans réponse de votre part, il est payé automatiquement le ".$auto?->translatedFormat('j F').'.',
        $commande->statut === StatutCommande::Terminee && ! $confirmee && $role === 'prestataire' => 'Prestation terminée. Le client doit confirmer la réception ; sans réponse de sa part, vous êtes payé automatiquement le '.$auto?->translatedFormat('j F').'.',
        $commande->statut === StatutCommande::Terminee && $confirmee => $role === 'client' ? 'Commande terminée. Le prestataire a été payé.' : 'Commande terminée : le paiement a été versé sur votre wallet.',
        $commande->statut === StatutCommande::Annulee => 'Commande annulée. '.($commande->escrow?->statut === Escrow::REMBOURSE ? 'Le montant bloqué a été remboursé au client.' : ''),
        $commande->statut === StatutCommande::Litige => 'Un problème a été signalé. L\'argent reste bloqué en séquestre jusqu\'à la décision d\'un administrateur.',
        default => '',
    };

    $escrow = $commande->escrow;
    $etatEscrow = $physique ? ['Vous réglez le prestataire en main propre. Aucun argent n\'est prélevé ni bloqué par KoudMain pour cette commande.', 'info'] : match ($escrow?->statut) {
        Escrow::BLOQUE => [$role === 'prestataire' ? 'Le client a payé : l\'argent est en séquestre et vous sera versé à la fin de la prestation.' : 'Votre argent est bloqué en séquestre. Le prestataire n\'est payé qu\'à la fin de la prestation.', 'cadenas'],
        Escrow::LITIGE => ['L\'argent est bloqué en attendant l\'arbitrage.', 'interdit'],
        Escrow::LIBERE => ['Versé au prestataire le '.$escrow->libere_at?->translatedFormat('j M Y').'.', 'coche'],
        Escrow::REMBOURSE => ['Remboursé au client le '.$escrow->rembourse_at?->translatedFormat('j M Y').'.', 'coche'],
        default => ['Aucun séquestre pour cette commande.', 'info'],
    };
@endphp
<x-layouts.espace :titre="'Commande n° '.$commande->id" :page="'Commande n° '.$commande->id" :recherche="false">
    <div data-region="commande" data-region-evenements="commande notification">
    <x-espace.entete :etiquette="'Commande n° '.$commande->id" :titre="$ligne?->titre ?? 'Prestation supprimée'" :intro="$situation">
        <x-espace.statut :statut="$commande->statut" class="text-sm" />
        <a href="{{ route($role.'.commandes') }}" class="btn btn-petit"><x-icone nom="chevron-gauche" taille="size-4" /><span>Toutes les commandes</span></a>
    </x-espace.entete>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div class="grid gap-6">
            {{-- ------------------------------------------------------------ Détail --}}
            <x-espace.panneau titre="Détail de la commande" data-reveal>
                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-faint">Prestation</dt>
                        <dd class="mt-1 font-medium">
                            @if ($ligne)
                                <a href="{{ route('prestations.voir', $ligne) }}" class="lien">{{ $ligne->titre }}</a>
                            @else
                                Prestation supprimée
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Quantité et prix</dt>
                        <dd class="mt-1 font-medium tabular-nums">{{ $ligne?->pivot->quantite ?? 1 }} × {{ Format::fcfa($ligne?->pivot->prix_unitaire ?? $commande->montant_total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Total</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums">{{ Format::fcfa($commande->montant_total) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Mode de paiement</dt>
                        <dd class="mt-1 flex items-center gap-2 font-medium"><x-icone :nom="$mode->icone()" taille="size-4" class="text-soft" /><span>{{ $mode->libelle() }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Jour et heure</dt>
                        <dd class="mt-1 font-medium">
                            @if ($commande->date_souhaitee)
                                {{ \Illuminate\Support\Str::ucfirst($commande->date_souhaitee->translatedFormat('l j F Y \à H:i')) }}
                                @if ($commande->duree_minutes)<span class="block text-sm font-normal text-faint">Durée prévue : {{ \App\Support\Duree::libelle($commande->duree_minutes) }}</span>@endif
                            @else
                                <span class="font-normal text-soft">Non précisé</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Lieu</dt>
                        <dd class="mt-1 font-medium">{{ $commande->quartier->nom }}, {{ $commande->quartier->ville->nom }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-faint">Passée le</dt>
                        <dd class="mt-1 font-medium">{{ $commande->created_at->translatedFormat('j F Y \à H:i') }}</dd>
                    </div>
                    @if ($commande->precisions)
                        <div class="sm:col-span-2">
                            <dt class="text-sm text-faint">Précisions du client</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $commande->precisions }}</dd>
                        </div>
                    @endif
                    @if ($commande->motif_annulation)
                        <div class="sm:col-span-2">
                            <dt class="text-sm text-faint">Motif d'annulation</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $commande->motif_annulation }}</dd>
                        </div>
                    @endif
                </dl>
            </x-espace.panneau>

            @if ($commande->motif_litige)
                <x-espace.panneau titre="Problème signalé par le client" data-reveal>
                    <p class="whitespace-pre-line p-5">{{ $commande->motif_litige }}</p>
                </x-espace.panneau>
            @endif

            {{-- ------------------------------------------------------------ Arbitrage (administrateur) --}}
            @if ($role === 'admin' && $commande->statut === StatutCommande::Litige)
                <x-espace.panneau titre="Trancher ce litige" etiquette="Décision de l'administration" data-reveal>
                    <form method="POST" action="{{ route('admin.commandes.arbitrer', $commande) }}" class="grid gap-5 p-5"
                          data-confirmer="{{ $physique ? 'Trancher ce litige ? La décision est définitive (aucun argent ne bouge, la commande était à régler en main propre).' : 'Trancher ce litige ? L\'argent sera versé tout de suite et la décision est définitive.' }}" data-confirmer-bouton="Trancher" data-confirmer-ton="neutre">
                        @csrf
                        <fieldset class="grid gap-3">
                            <legend class="mb-1 text-sm font-medium">{{ $physique ? 'Quelle issue donner à ce litige ?' : 'Qui reçoit les '.Format::fcfa($commande->montant_total).' ?' }}</legend>
                            <div class="choix">
                                <label><input type="radio" name="decision" value="prestataire" required> <span>Le prestataire<br><small class="text-faint">{{ $prestataire->nom_complet }}</small></span></label>
                                <label><input type="radio" name="decision" value="client" required> <span>{{ $physique ? 'Le client (commande annulée)' : 'Le client (remboursement)' }}<br><small class="text-faint">{{ $client->nom_complet }}</small></span></label>
                            </div>
                        </fieldset>
                        <div class="champ">
                            <label for="note-arbitrage">Note interne <span class="font-normal text-faint">(facultatif)</span></label>
                            <div class="champ-saisie"><textarea id="note-arbitrage" name="note" rows="2" maxlength="500" placeholder="Ce qui justifie la décision"></textarea></div>
                        </div>
                        <div><button type="submit" class="btn btn-plein" data-chargement="Un instant…"><span data-libelle>Trancher</span></button></div>
                    </form>
                </x-espace.panneau>
            @endif


            {{-- ------------------------------------------------------------ Avis --}}
            @if ($peutNoter)
                <x-espace.panneau id="avis" titre="Votre avis" etiquette="Donnez une note" data-reveal>
                    <div class="grid gap-6 p-5">
                        @foreach ($commande->prestations as $p)
                            @php($avis = $commande->avis->firstWhere('prestation_id', $p->id))
                            <form method="POST" action="{{ route('client.commandes.avis', $commande) }}" class="grid gap-4">
                                @csrf
                                <input type="hidden" name="prestation_id" value="{{ $p->id }}">
                                <div>
                                    <p class="font-medium">{{ $p->titre }}</p>
                                    <p class="text-sm text-faint">{{ $avis ? 'Vous avez noté cette prestation : vous pouvez modifier votre avis.' : 'Comment s\'est passée la prestation ? Votre note aide les autres clients.' }}</p>
                                </div>
                                <x-espace.notation :id="'note-'.$p->id" :valeur="(int) old('note', $avis?->note ?? 0)" />
                                <div class="champ">
                                    <label for="commentaire-{{ $p->id }}">Commentaire <span class="font-normal text-faint">(facultatif)</span></label>
                                    <div class="champ-saisie"><textarea id="commentaire-{{ $p->id }}" name="commentaire" rows="3" maxlength="{{ \App\Services\AvisService::COMMENTAIRE_MAX }}" placeholder="Ponctualité, qualité du travail, accueil…">{{ old('commentaire', $avis?->commentaire) }}</textarea></div>
                                </div>
                                <div><button type="submit" class="btn btn-plein" data-chargement="Un instant…"><span data-libelle>{{ $avis ? 'Modifier mon avis' : 'Publier mon avis' }}</span></button></div>
                            </form>
                        @endforeach
                    </div>
                </x-espace.panneau>
            @elseif ($commande->avis->isNotEmpty() && $role !== 'admin')
                <x-espace.panneau id="avis" :titre="$role === 'client' ? 'Votre avis' : 'Avis du client'" data-reveal>
                    <ul role="list" class="divide-y divide-line">
                        @foreach ($commande->avis as $a)
                            <li class="p-5">
                                <p class="flex items-center gap-1 text-amber" aria-label="{{ $a->note }} sur 5">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <svg class="size-4 {{ $i <= $a->note ? 'fill-amber' : 'fill-transparent text-edge' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96L12 2.5z"/></svg>
                                    @endfor
                                </p>
                                @if ($a->commentaire)<p class="mt-2 whitespace-pre-line text-sm">{{ $a->commentaire }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </x-espace.panneau>
            @endif

            {{-- ------------------------------------------------------------ Échanges (administrateur, lecture seule) --}}
            @if ($role === 'admin')
                <x-espace.panneau titre="Échanges entre les parties" :etiquette="$transcription->isEmpty() ? 'Aucun message' : 'Lecture seule'" data-reveal>
                    @if ($transcription->isEmpty())
                        <x-espace.vide icone="message" titre="Aucun message" texte="Le client et le prestataire n'ont pas échangé sur cette commande." />
                    @else
                        <ul role="list" class="grid max-h-96 gap-3 overflow-y-auto p-5">
                            @foreach ($transcription as $m)
                                <li class="text-sm">
                                    <p class="flex items-baseline justify-between gap-3">
                                        <strong class="font-medium">{{ $m->expediteur->nom_complet }} <span class="font-normal text-faint">({{ $m->expediteur_id === $client->id ? 'client' : 'prestataire' }})</span></strong>
                                        <time class="shrink-0 text-xs text-faint" datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->translatedFormat('j M, H:i') }}</time>
                                    </p>
                                    <p class="mt-0.5 whitespace-pre-line text-soft">{{ $m->contenu }}</p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-espace.panneau>
            @endif

            <x-espace.panneau titre="Suivi" data-reveal>
                <x-commande.chronologie :commande="$commande" />
            </x-espace.panneau>
        </div>

        {{-- ------------------------------------------------------------ Colonne latérale --}}
        <div class="grid gap-6">
            @if ($actions !== [])
                <x-espace.panneau titre="Que faire maintenant ?" data-reveal>
                    <div class="p-5"><x-commande.actions :commande="$commande" :actions="$actions" :role="$role" class="[&>form]:w-full [&>form>button]:w-full [&>form>button]:justify-center" /></div>
                </x-espace.panneau>
            @endif


            @if ($discussion !== null)
                <x-espace.panneau titre="Discussion" :etiquette="$discussion['non_lus'] > 0 ? $discussion['non_lus'].' non lu'.($discussion['non_lus'] > 1 ? 's' : '') : null" data-reveal>
                    <div class="p-5">
                        @if ($discussion['apercu'])
                            <p class="text-sm text-soft"><span class="text-faint">{{ $discussion['apercu_moi'] ? 'Vous : ' : $partie->prenom.' : ' }}</span>{{ $discussion['apercu'] }}</p>
                        @else
                            <p class="text-sm text-soft">Posez vos questions ou précisez l'adresse : {{ $partie->prenom }} vous répond ici, en direct.</p>
                        @endif
                        <a href="{{ route('messages.voir', $commande) }}" class="btn btn-petit mt-4"><x-icone nom="message" taille="size-4" /><span>{{ $discussion['nombre'] > 0 ? 'Ouvrir la discussion' : 'Écrire un message' }}</span></a>
                    </div>
                </x-espace.panneau>
            @endif

            <x-espace.panneau titre="Paiement" data-reveal>
                <div class="p-5">
                    <p class="text-2xl font-semibold tabular-nums">{{ Format::fcfa($commande->montant_total) }}</p>
                    <p class="mt-1.5 flex items-center gap-2 text-sm text-soft"><x-icone :nom="$mode->icone()" taille="size-4" /><span>{{ $mode->libelle() }}</span></p>
                    <p class="mt-3 flex items-start gap-2.5 text-sm text-soft"><x-icone :nom="$etatEscrow[1]" taille="size-4" class="mt-0.5 shrink-0" /><span>{{ $etatEscrow[0] }}</span></p>
                </div>
            </x-espace.panneau>

            @if ($role === 'admin')
                @foreach ([['Client', $client], ['Prestataire', $prestataire]] as [$roleLibelle, $personne])
                    <x-espace.panneau :titre="$roleLibelle" data-reveal>
                        <div class="flex items-center gap-3.5 p-5">
                            <x-avatar :utilisateur="$personne" taille="size-11 text-sm" />
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ $personne->nom_complet }}</p>
                                <p class="truncate text-sm text-faint">{{ $personne->email }} · {{ $personne->telephone }}</p>
                            </div>
                        </div>
                    </x-espace.panneau>
                @endforeach
            @else
                <x-espace.panneau :titre="$role === 'client' ? 'Votre prestataire' : 'Votre client'" data-reveal>
                    <div class="flex items-center gap-3.5 p-5">
                        <x-avatar :utilisateur="$partie" taille="size-11 text-sm" />
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                @if ($role === 'client')
                                    <a href="{{ route('prestataires.voir', $partie) }}" class="lien">{{ $partie->nom_complet }}</a>
                                @else
                                    {{ $partie->nom_complet }}
                                @endif
                            </p>
                            <p class="truncate text-sm text-faint">{{ $partie->quartier->nom }}</p>
                            {{-- Le numéro n'est partagé qu'une fois la commande acceptée. --}}
                            @if ($accepteeOuPlus)
                                <p class="mt-1 text-sm"><a href="tel:+225{{ $partie->telephone }}" class="lien">{{ $partie->telephone }}</a></p>
                            @endif
                        </div>
                    </div>
                </x-espace.panneau>
            @endif
        </div>
    </div>
    </div>
</x-layouts.espace>
