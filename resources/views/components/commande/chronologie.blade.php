{{-- Le suivi d'une commande, étape par étape (les étapes non atteintes restent en gris). --}}
@props(['commande'])
@php
    use App\Enums\StatutCommande;
    use Carbon\CarbonInterface;

    $quand = fn (?CarbonInterface $date) => $date?->translatedFormat('j M Y \à H:i');
    $etapes = [['Commande envoyée', $commande->created_at, true, false]];

    if ($commande->statut === StatutCommande::Annulee && $commande->acceptee_at === null) {
        $etapes[] = ['Commande annulée', $commande->annulee_at, true, 'annulee'];
    } else {
        $etapes[] = ['Acceptée par le prestataire', $commande->acceptee_at, $commande->acceptee_at !== null, false];

        if ($commande->statut === StatutCommande::Annulee && $commande->debut_at === null) {
            $etapes[] = ['Commande annulée', $commande->annulee_at, true, 'annulee'];
        } else {
            $etapes[] = ['Prestation démarrée', $commande->debut_at, $commande->debut_at !== null, false];
            $etapes[] = ['Prestation terminée', $commande->terminee_at, $commande->terminee_at !== null, false];

            if ($commande->statut === StatutCommande::Litige) {
                $etapes[] = ['Problème signalé : en cours d\'examen par l\'administration', null, true, 'litige'];
            } elseif ($commande->statut === StatutCommande::Annulee) {
                $etapes[] = ['Commande annulée', $commande->annulee_at, true, 'annulee'];
            } else {
                $etapes[] = [$commande->payeeEnPhysique() ? 'Réception confirmée' : 'Réception confirmée, prestataire payé', $commande->validee_client_at, $commande->validee_client_at !== null, false];
            }
        }
    }
@endphp
<ol {{ $attributes->class('suivi') }} aria-label="Suivi de la commande">
    @foreach ($etapes as [$libelle, $date, $fait, $nuance])
        <li @if ($nuance === 'annulee') data-danger @elseif ($nuance === 'litige') data-litige @elseif ($fait) data-fait @endif>
            <p class="text-sm font-medium">{{ $libelle }}@unless ($fait) <span class="sr-only"> (pas encore)</span>@endunless</p>
            @if ($date)<p class="text-sm text-faint">{{ $quand($date) }}</p>@endif
        </li>
    @endforeach
</ol>
