{{--
    Diagramme en barres, dessiné côté serveur (aucune bibliothèque, aucun script pour l'afficher) : UNE série, une couleur
    (terracotta), barres fines aux bouts arrondis posées sur la ligne de base, grille discrète, deux repères d'axe (haut et 0).
    - `points`  : liste de ['jour' => 'AAAA-MM-JJ', 'valeur' => nombre] ;
    - `unite`   : « commande » / « compte » / « FCFA » (accord au pluriel automatique, sauf FCFA) ;
    - `titre`   : nom du graphique, lu par les lecteurs d'écran (« Commandes par jour ») ;
    - le détail jour par jour est toujours disponible en tableau (« Voir les données »), pour le clavier et les lecteurs d'écran ;
    - la bulle au survol est posée par resources/js/lib/graphes.js.
--}}
@props(['points', 'unite' => '', 'titre', 'hauteur' => '9rem'])
@php
    $argent = $unite === 'FCFA';
    $valeurs = array_map(fn ($p) => (float) $p['valeur'], $points);
    $max = $valeurs === [] ? 0 : max($valeurs);
    $total = array_sum($valeurs);

    // Échelle « ronde » : 7 -> 8, 16 -> 20, 4 300 -> 5 000.
    $echelle = 1;
    if ($max > 0) {
        $e = 10 ** floor(log10($max));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $f) {
            if ($max <= $f * $e) { $echelle = $f * $e; break; }
        }
    }

    $texte = fn (float $v) => $argent ? \App\Support\Format::fcfa($v) : \App\Support\Format::pluriel((int) round($v), $unite);
    $date = fn (string $j) => \Illuminate\Support\Carbon::parse($j)->translatedFormat('D j M');
    $pic = $max > 0 ? $points[array_search($max, $valeurs, true)] : null;
    $milieu = count($points) > 14 ? $points[intdiv(count($points), 2)] : null;

    $resume = $titre.' : '.($total > 0
        ? 'au total '.$texte($total).', pic de '.$texte($max).' le '.$date($pic['jour']).'.'
        : 'aucune activité sur la période.');
@endphp
<figure {{ $attributes->class('graphe') }} data-graphe>
    <div class="graphe-cadre" style="--hauteur: {{ $hauteur }}">
        <div class="graphe-axe" aria-hidden="true">
            <span>{{ $argent ? \App\Support\Format::court($echelle) : (int) $echelle }}</span>
            <span>0</span>
        </div>
        <div class="graphe-barres" role="img" aria-label="{{ $resume }}">
            @foreach ($points as $p)
                @php $v = (float) $p['valeur']; @endphp
                <span class="graphe-col" data-libelle="{{ $date($p['jour']) }}" data-valeur="{{ $texte($v) }}">
                    @if ($v > 0)<i class="graphe-barre" style="--h: {{ round($v / $echelle * 100, 2) }}%"></i>@endif
                </span>
            @endforeach
        </div>
    </div>
    <div class="graphe-dates" aria-hidden="true">
        <span>{{ $date($points[0]['jour']) }}</span>
        @if ($milieu)<span>{{ $date($milieu['jour']) }}</span>@endif
        <span>{{ $date($points[array_key_last($points)]['jour']) }}</span>
    </div>
    <details class="graphe-donnees">
        <summary class="lien text-sm">Voir les données</summary>
        <div class="graphe-table">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ $resume }}</caption>
                <thead><tr><th scope="col" class="text-left font-medium">Jour</th><th scope="col" class="text-right font-medium">{{ $argent ? 'Montant' : ucfirst($unite).'s' }}</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($points) as $p)
                        <tr><th scope="row" class="text-left font-normal">{{ $date($p['jour']) }}</th><td class="text-right tabular-nums">{{ $argent ? \App\Support\Format::fcfa($p['valeur']) : (int) $p['valeur'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</figure>
