{{--
    Les actions possibles sur une commande, pour l'utilisateur connecté (calculées par ActionCommande::possibles : la vue ne décide de rien,
    et le serveur revérifie tout à l'envoi).
    `compact` : dans une liste, on ne montre que les actions en un clic ; celles qui demandent un motif (annuler, signaler un problème)
    se font depuis la page de la commande.
--}}
@props(['commande', 'actions', 'role', 'compact' => false])
@php
    use App\Enums\ActionCommande;

    $physique = $commande->payeeEnPhysique();
    $confirmations = [
        'annuler' => [$physique ? 'Annuler cette commande ?' : 'Annuler cette commande ? Le montant bloqué sera remboursé au client.', 'Annuler la commande', null],
        'confirmer_reception' => [$physique ? 'Confirmer la réception ? Vous ne pourrez plus signaler de problème.' : 'Confirmer la réception ? Le prestataire sera payé tout de suite et vous ne pourrez plus signaler de problème.', $physique ? 'Confirmer la réception' : 'Confirmer et payer', 'neutre'],
        'terminer' => ['Marquer la prestation comme terminée ? Le client sera invité à confirmer la réception.', 'Marquer terminée', 'neutre'],
    ];
@endphp
<div {{ $attributes->class('flex flex-wrap items-start gap-2') }}>
    @foreach ($actions as $action)
        @continue($compact && $action->motifPossible())

        @if ($action->motifPossible())
            <details class="motif w-full" @if (old('motif') && session('erreur')) open @endif>
                <summary class="btn btn-petit {{ $action === ActionCommande::Annuler ? 'btn-danger' : '' }}"><span>{{ $action->libelle() }}</span></summary>
                <form method="POST" action="{{ route($role.'.commandes.agir', [$commande, $action->value]) }}"
                      @if (isset($confirmations[$action->value])) data-confirmer="{{ $confirmations[$action->value][0] }}" data-confirmer-bouton="{{ $confirmations[$action->value][1] }}" @endif>
                    @csrf
                    <div class="champ">
                        <label for="motif-{{ $action->value }}-{{ $commande->id }}">
                            {{ $action === ActionCommande::OuvrirLitige ? 'Décrivez le problème' : 'Motif' }}
                            @unless ($action->motifObligatoire()) <span class="font-normal text-faint">(facultatif)</span> @endunless
                        </label>
                        <div class="champ-saisie">
                            <textarea id="motif-{{ $action->value }}-{{ $commande->id }}" name="motif" rows="3" maxlength="1000" @required($action->motifObligatoire())
                                      placeholder="{{ $action === ActionCommande::OuvrirLitige ? 'Ce qui ne va pas, en quelques mots : un administrateur en tiendra compte pour trancher.' : 'Pourquoi annulez-vous ? (le prestataire ou le client le verra)' }}"></textarea>
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-petit {{ $action === ActionCommande::Annuler ? 'btn-danger' : 'btn-plein' }}" data-chargement="Un instant…"><span data-libelle>{{ $action === ActionCommande::OuvrirLitige ? 'Envoyer le signalement' : 'Confirmer l\'annulation' }}</span></button>
                    </div>
                </form>
            </details>
        @else
            <form method="POST" action="{{ route($role.'.commandes.agir', [$commande, $action->value]) }}"
                  @if (isset($confirmations[$action->value])) data-confirmer="{{ $confirmations[$action->value][0] }}" data-confirmer-bouton="{{ $confirmations[$action->value][1] }}" @if ($confirmations[$action->value][2]) data-confirmer-ton="{{ $confirmations[$action->value][2] }}" @endif @endif>
                @csrf
                <button type="submit" class="btn btn-petit {{ in_array($action, [ActionCommande::Accepter, ActionCommande::ConfirmerReception], true) ? 'btn-plein' : '' }}" data-chargement="Un instant…"><span data-libelle>{{ $action->libelle() }}</span></button>
            </form>
        @endif
    @endforeach
</div>
