{{-- Statut d'une commande : libellé + couleur (orange, bleu, violet, vert, rouge, gris) ; jamais la couleur seule. --}}
@props(['statut'])
<span {{ $attributes->class(['statut', 'statut-'.$statut->nuance()]) }}>{{ $statut->libelle() }}</span>
