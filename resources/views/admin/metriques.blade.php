@use('App\Support\Format')
@php
    // Évolution par rapport à la période précédente (de même durée) : « +25 % », « -10 % » ou une phrase claire si elle était vide.
    $evolution = function (float $actuel, float $precedent, string $sujet): string {
        if ($precedent <= 0) {
            return $actuel > 0 ? 'Rien sur la période précédente' : 'Aucun '.$sujet.' sur la période';
        }

        $pct = (int) round(($actuel - $precedent) / $precedent * 100);

        return ($pct > 0 ? '+' : ($pct < 0 ? '−' : '')).abs($pct).' % sur la période précédente';
    };

    // Variation d'un chiffre d'état depuis le premier instantané de la période.
    $depuis = function (string $cle, float $actuel, string $unite = '') use ($m): string {
        $depart = $m['departs'][$cle] ?? null;

        if ($depart === null || $depart['date'] >= now()->toDateString()) {
            return 'La comparaison apparaît dès demain';
        }

        $ecart = $actuel - $depart['valeur'];
        $quand = \Illuminate\Support\Carbon::parse($depart['date'])->translatedFormat('j M');
        $nombre = $unite === 'FCFA' ? Format::montant(abs($ecart)).' FCFA' : (string) (int) abs($ecart);

        return $ecart == 0 ? "Stable depuis le $quand" : ($ecart > 0 ? '+' : '−').$nombre." depuis le $quand";
    };

    $taux = $k['cloturees_periode'] > 0 ? (int) round($k['terminees_periode'] / $k['cloturees_periode'] * 100) : null;
    $nomsStatuts = ['en_attente' => 'En attente', 'acceptee' => 'Acceptées', 'en_cours' => 'En cours', 'terminee' => 'Terminées', 'annulee' => 'Annulées', 'litige' => 'Litiges'];
    $nuances = ['en_attente' => 'attente', 'acceptee' => 'acceptee', 'en_cours' => 'encours', 'terminee' => 'terminee', 'annulee' => 'annulee', 'litige' => 'litige'];
    $totalStatuts = array_sum($m['statuts']);
    $icones = ['ok' => 'coche', 'attention' => 'alerte', 'erreur' => 'interdit', 'info' => 'info'];
    $mots = ['ok' => 'En ordre', 'attention' => 'Attention', 'erreur' => 'Problème', 'info' => 'Info'];
    $nomsTaches = ['liberation-escrows' => 'Libération des paiements', 'nettoyage' => 'Nettoyage des données périmées', 'instantane-metriques' => 'Instantané des indicateurs', 'temps-reel-purge' => 'Purge du temps réel'];
    $maxCategorie = max(1, ...array_column($m['categories'], 'commandes') ?: [1]);
    $maxPrestation = max(1, ...array_column($m['prestations'], 'commandes') ?: [1]);
@endphp
<x-layouts.espace titre="Métriques et santé" :recherche="false">
    <x-espace.entete etiquette="Administration" titre="Métriques" suite="et santé"
                     intro="L'activité de la plateforme sur la période choisie, et l'état de ses rouages : base de données, tâches automatiques, e-mails, paiements.">
        <nav class="flex flex-wrap items-center gap-2" aria-label="Période">
            @foreach ($periodes as $p)
                <a href="{{ route('admin.metriques', ['jours' => $p]) }}" @class(['puce', 'bg-ink text-paper' => $p === $jours]) @if ($p === $jours) aria-current="page" @endif>{{ $p }} jours</a>
            @endforeach
            <a href="{{ route('admin.metriques', ['jours' => $jours, 'actualiser' => 1]) }}" class="puce" title="Recalculer les chiffres maintenant"><x-icone nom="rafraichir" taille="size-3.5" /><span>Actualiser</span></a>
        </nav>
    </x-espace.entete>

    {{-- Santé en un coup d'œil : le détail est plus bas. --}}
    <a href="#sante" data-reveal class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-line bg-surface px-5 py-4">
        <span class="sante-niveau" data-niveau="{{ $synthese['niveau'] }}"><x-icone :nom="$icones[$synthese['niveau']]" taille="size-3.5" />{{ $mots[$synthese['niveau']] }}</span>
        <span class="font-medium">{{ $synthese['titre'] }}</span>
        <span class="text-sm text-faint">Santé du système, vérifiée à l'instant</span>
        <span class="ml-auto inline-flex items-center gap-1.5 text-sm text-soft">Voir le détail <x-icone nom="fleche-bas" taille="size-3.5" /></span>
    </a>

    <p class="mb-3 text-sm font-medium text-faint">Sur les {{ $jours }} derniers jours</p>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-espace.stat data-reveal libelle="Commandes passées" :valeur="$k['commandes_periode']" icone="liste" :note="$evolution($k['commandes_periode'], $k['commandes_precedent'], 'commande')" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Versé aux prestataires" :valeur="Format::montant($k['libere_periode'])" unite="FCFA" icone="portefeuille" :note="$evolution($k['libere_periode'], $k['libere_precedent'], 'versement')" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Nouveaux comptes" :valeur="$k['comptes_periode']" icone="utilisateurs" :note="$evolution($k['comptes_periode'], $k['comptes_precedent'], 'nouveau compte')" />
        <x-espace.stat data-reveal style="--i: 3" libelle="Menées à terme" :valeur="$taux === null ? '—' : $taux" :unite="$taux === null ? null : '%'" icone="coche"
                       :note="$taux === null ? 'Aucune commande clôturée sur la période' : $k['terminees_periode'].' terminées sur '.$k['cloturees_periode'].' clôturées'" />
    </div>

    <p class="mb-3 text-sm font-medium text-faint">La plateforme aujourd'hui</p>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-espace.stat data-reveal libelle="Comptes inscrits" :valeur="$k['comptes']" icone="utilisateurs" :note="$depuis('etat.comptes', $k['comptes'])" />
        <x-espace.stat data-reveal style="--i: 1" libelle="Prestataires validés" :valeur="$k['prestataires_valides']" icone="utilisateur-valide"
                       :note="$k['a_valider'] > 0 ? Format::pluriel($k['a_valider'], 'profil').' à valider' : $depuis('etat.prestataires_valides', $k['prestataires_valides'])" />
        <x-espace.stat data-reveal style="--i: 2" libelle="Commandes en cours" :valeur="$k['commandes_ouvertes']" icone="colis" :note="$depuis('etat.commandes_ouvertes', $k['commandes_ouvertes'])" />
        <x-espace.stat data-reveal style="--i: 3" libelle="Argent en séquestre" :valeur="Format::montant($k['sequestre'])" unite="FCFA" icone="cadenas" :note="$depuis('etat.sequestre_fcfa', $k['sequestre'], 'FCFA')" />
    </div>

    <x-espace.panneau titre="Activité" etiquette="Jour par jour" class="mb-6" data-reveal>
        <div class="grid gap-x-8 gap-y-8 p-5 lg:grid-cols-2">
            <div class="min-w-0">
                <h3 class="mb-3 text-base font-semibold">Commandes par jour</h3>
                <x-espace.graphique :points="$m['commandes_par_jour']" unite="commande" titre="Commandes par jour" />
            </div>
            <div class="min-w-0">
                <h3 class="mb-3 text-base font-semibold">Nouveaux comptes par jour</h3>
                <x-espace.graphique :points="$m['comptes_par_jour']" unite="compte" titre="Nouveaux comptes par jour" />
            </div>
            <div class="min-w-0 lg:col-span-2">
                <h3 class="mb-3 text-base font-semibold">Argent versé aux prestataires, par jour</h3>
                <x-espace.graphique :points="$m['libere_par_jour']" unite="FCFA" titre="Argent versé aux prestataires par jour" hauteur="8rem" />
            </div>
        </div>
    </x-espace.panneau>

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <x-espace.panneau titre="Commandes par statut" :etiquette="Format::pluriel($totalStatuts, 'commande').' passée'.($totalStatuts > 1 ? 's' : '')" data-reveal>
            @if ($totalStatuts === 0)
                <x-espace.vide titre="Aucune commande" texte="Rien n'a été commandé sur cette période." />
            @else
                <ul role="list" class="space-y-4 p-5">
                    @foreach ($nomsStatuts as $statut => $libelle)
                        @continue(($m['statuts'][$statut] ?? 0) === 0)
                        <li class="ligne-barre" data-nuance="{{ $nuances[$statut] }}">
                            <span class="text-sm">{{ $libelle }}</span>
                            <span class="text-sm tabular-nums"><strong class="font-semibold">{{ $m['statuts'][$statut] }}</strong> <span class="text-faint">· {{ round($m['statuts'][$statut] / $totalStatuts * 100) }} %</span></span>
                            <span class="piste" aria-hidden="true"><i style="--l: {{ round($m['statuts'][$statut] / $totalStatuts * 100, 1) }}%"></i></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-espace.panneau>

        <x-espace.panneau titre="Catégories les plus demandées" etiquette="Commandes non annulées" data-reveal style="--i: 1">
            @if ($m['categories'] === [])
                <x-espace.vide titre="Rien à classer" texte="Les catégories apparaîtront avec les premières commandes." />
            @else
                <ul role="list" class="space-y-4 p-5">
                    @foreach ($m['categories'] as $c)
                        <li class="ligne-barre">
                            <span class="min-w-0 truncate text-sm">{{ $c['nom'] }}</span>
                            <span class="text-sm tabular-nums"><strong class="font-semibold">{{ $c['commandes'] }}</strong> <span class="text-faint">· {{ Format::fcfa($c['montant']) }}</span></span>
                            <span class="piste" aria-hidden="true"><i style="--l: {{ round($c['commandes'] / $maxCategorie * 100, 1) }}%"></i></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-espace.panneau>

        <x-espace.panneau titre="Prestations les plus commandées" etiquette="Commandes non annulées" data-reveal style="--i: 2">
            @if ($m['prestations'] === [])
                <x-espace.vide titre="Rien à classer" texte="Les prestations les plus demandées apparaîtront ici." />
            @else
                <ul role="list" class="space-y-4 p-5">
                    @foreach ($m['prestations'] as $p)
                        <li class="ligne-barre">
                            <span class="min-w-0 text-sm"><span class="block truncate">{{ $p['titre'] }}</span><span class="block truncate text-xs text-faint">{{ $p['prestataire'] }}</span></span>
                            <strong class="text-sm font-semibold tabular-nums">{{ $p['commandes'] }}</strong>
                            <span class="piste" aria-hidden="true"><i style="--l: {{ round($p['commandes'] / $maxPrestation * 100, 1) }}%"></i></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-espace.panneau>
    </div>

    <x-espace.panneau titre="Confiance et sécurité" :etiquette="'Sur les '.$jours.' derniers jours'" class="mb-6" data-reveal>
        <dl class="grid divide-y divide-line sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 [&>div]:px-5 [&>div]:py-4 sm:[&>div]:border-b sm:[&>div]:border-line lg:[&>div]:border-b-0">
            <div><dt class="text-sm text-soft">Connexions réussies</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $m['connexions']['connexion.succes'] }}</dd></div>
            <div><dt class="text-sm text-soft">Mots de passe refusés</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $m['connexions']['connexion.echec'] }}</dd></div>
            <div><dt class="text-sm text-soft">Tentatives bloquées</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $m['connexions']['connexion.bloquee'] }}</dd></div>
            <div><dt class="text-sm text-soft">Litiges non tranchés</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $k['litiges'] }}</dd>
                @if ($k['litiges'] > 0)<a href="{{ route('admin.commandes', ['statut' => 'litige']) }}" class="lien mt-1 inline-block text-sm">Les traiter</a>@endif
            </div>
            <div><dt class="text-sm text-soft">Recharges réussies</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $k['recharges_reussies'] }}</dd><p class="mt-0.5 text-sm text-faint">{{ Format::fcfa($k['recharges_montant']) }}</p></div>
            <div><dt class="text-sm text-soft">Recharges échouées</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $k['recharges_echouees'] }}</dd></div>
            <div><dt class="text-sm text-soft">Remboursé aux clients</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ Format::montant($k['rembourse_periode']) }} <span class="text-base font-normal text-soft">FCFA</span></dd></div>
            <div><dt class="text-sm text-soft">Retraits à virer</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $k['retraits_attente'] }}</dd>
                <p class="mt-0.5 text-sm text-faint">{{ Format::fcfa($k['retraits_montant']) }}@if ($k['retraits_attente'] > 0) · <a href="{{ route('admin.retraits', ['statut' => 'en_attente']) }}" class="lien">Traiter</a>@endif</p>
            </div>
        </dl>
        <div class="grid divide-y divide-line border-t border-line sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <p class="px-5 py-4 text-sm"><span class="text-soft">Délai moyen avant qu'un prestataire accepte :</span> <strong class="font-semibold">{{ $k['delai_acceptation'] === null ? '—' : Format::duree($k['delai_acceptation']) }}</strong></p>
            <p class="px-5 py-4 text-sm"><span class="text-soft">Note moyenne des avis :</span> <strong class="font-semibold">{{ $k['note_moyenne'] === null ? '—' : Format::note((float) $k['note_moyenne']).' / 5' }}</strong> <span class="text-faint">({{ Format::pluriel($k['nb_avis'], 'avis', 'avis') }})</span></p>
        </div>
    </x-espace.panneau>

    <x-espace.panneau id="sante" titre="Santé du système" etiquette="Vérifiée à l'instant" class="mb-6 scroll-mt-20" data-reveal>
        <x-slot:actions><span class="sante-niveau" data-niveau="{{ $synthese['niveau'] }}"><x-icone :nom="$icones[$synthese['niveau']]" taille="size-3.5" />{{ $synthese['titre'] }}</span></x-slot:actions>
        <ul role="list" class="divide-y divide-line">
            @foreach ($controles as $c)
                <li class="flex flex-wrap items-start gap-x-4 gap-y-1.5 px-5 py-3.5">
                    <span class="sante-niveau mt-0.5 w-[6.5rem] shrink-0 justify-center" data-niveau="{{ $c['niveau'] }}"><x-icone :nom="$icones[$c['niveau']]" taille="size-3.5" />{{ $mots[$c['niveau']] }}</span>
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="font-medium">{{ $c['nom'] }}</p>
                        <p class="text-sm text-soft">{{ $c['detail'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-espace.panneau>

    <x-espace.panneau titre="Tâches automatiques" etiquette="Planificateur" class="mb-6" data-reveal>
        @if ($taches->isEmpty())
            <x-espace.vide icone="horloge" titre="Aucune tâche n'a encore tourné" texte="Lancez le planificateur (php artisan schedule:work, ou Docker qui le fait pour vous) : chaque passage s'inscrira ici." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-faint">
                            <th scope="col" class="px-5 py-3 font-medium">Tâche</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-3 font-medium">Dernier passage</th>
                            <th scope="col" class="px-3 py-3 font-medium">Résultat</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Durée</th>
                            <th scope="col" class="px-5 py-3 text-right font-medium">Passages</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($taches as $t)
                            <tr>
                                <th scope="row" class="px-5 py-3 text-left font-medium"><span class="block">{{ $nomsTaches[$t->nom] ?? $t->nom }}</span><span class="block font-mono text-xs font-normal text-faint">{{ $t->nom }}</span></th>
                                <td class="whitespace-nowrap px-3 py-3 text-soft">{{ $t->derniere_execution ? $t->derniere_execution->translatedFormat('j M, H:i') : 'Jamais' }}</td>
                                <td class="px-3 py-3">
                                    <span @class(['statut', 'statut-ok' => $t->statut === 'ok', 'statut-danger' => $t->statut === 'erreur'])>{{ ['ok' => 'Réussi', 'erreur' => 'Échec', 'jamais' => 'Jamais lancé'][$t->statut] ?? $t->statut }}</span>
                                    @if ($t->resume)<span class="ml-2 text-soft">{{ $t->resume }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-soft">{{ $t->duree_ms === null ? '—' : ($t->duree_ms < 1000 ? $t->duree_ms.' ms' : number_format($t->duree_ms / 1000, 1, ',', '').' s') }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-soft">{{ $t->executions }}@if ($t->echecs > 0) <span class="text-danger">({{ $t->echecs }} échec{{ $t->echecs > 1 ? 's' : '' }})</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-espace.panneau>

    <p class="pb-2 text-center text-sm text-faint">Chiffres d'activité calculés à {{ $calculeA->format('H:i') }} (gardés {{ (int) round(config('koudmain.metriques.cache_secondes') / 60) }} min : « Actualiser » les recalcule).</p>
</x-layouts.espace>
