{{-- Une carte bancaire, dessinée en HTML/CSS (le même dessin que l'îlot React resources/js/islands/CartesWallet.jsx : c'est aussi son repli sans JavaScript). --}}
@props(['carte'])
@php($nomsReseaux = ['visa' => 'VISA', 'mastercard' => 'Mastercard', 'amex' => 'AMEX'])
<div {{ $attributes->class(['carte', 'carte-'.$carte['couleur']]) }}>
    <div class="carte-haut">
        <div>
            <p class="carte-nom">{{ $carte['libelle'] }}</p>
            <p class="carte-sous-nom">{{ $carte['principale'] ? 'Carte par défaut' : 'Carte bancaire' }}</p>
        </div>
        <span class="carte-reseau" data-reseau="{{ $carte['reseau'] }}">{{ $nomsReseaux[$carte['reseau']] ?? '' }}</span>
    </div>
    <span class="carte-puce" aria-hidden="true"></span>
    <p class="carte-numero" aria-hidden="true">{{ $carte['reseau'] === 'amex' ? '•••• •••••• •' : '•••• •••• •••• ' }}{{ $carte['fin'] }}</p>
    <div class="carte-bas">
        <div>
            <span class="carte-legende">Titulaire</span>
            <span class="carte-valeur">{{ $carte['titulaire'] }}</span>
        </div>
        <div>
            <span class="carte-legende">Expire</span>
            <span class="carte-valeur">{{ $carte['expire'] }}</span>
        </div>
        <span class="carte-marque" aria-hidden="true">koudmain</span>
    </div>
    @if ($carte['gelee'])
        <div class="carte-gel"><x-icone nom="cadenas" taille="size-5" />Carte gelée</div>
    @endif
</div>
