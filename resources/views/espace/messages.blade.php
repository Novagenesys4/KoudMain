{{--
    La messagerie : une discussion par commande.
    Le serveur écrit la page complète (liste + fil + formulaire d'envoi classique) : elle fonctionne sans JavaScript.
    L'îlot « Messagerie » la remplace ensuite par la version vivante (envoi sans rechargement, messages en direct).
--}}
@php
    $lettres = fn ($u) => mb_strtoupper(mb_substr($u['prenom'], 0, 1));
@endphp
<x-layouts.espace titre="Messages" :recherche="false">
    {{-- Sur téléphone, la messagerie prend tout l'écran : l'en-tête de page est masqué. --}}
    <div class="max-lg:hidden">
        <x-espace.entete etiquette="Vos discussions" titre="Messages" intro="Une discussion par commande, entre le client et le prestataire. Les messages arrivent en direct." />
    </div>

    <x-island nom="Messagerie" :donnees="['echanges' => $echanges, 'courante' => $courante, 'moi' => $moi, 'urls' => $urls]">
        <div class="msg" data-vue="{{ $courante ? 'fil' : 'liste' }}">
            <aside class="msg-liste" aria-label="Vos discussions">
                <div class="msg-liste-haut">
                    <h2>Discussions</h2>
                    @if ($nonLus > 0)<span class="msg-total">{{ $nonLus }} non lu{{ $nonLus > 1 ? 's' : '' }}</span>@endif
                </div>

                @if (count($echanges) === 0)
                    <p class="msg-vide-liste">Aucune commande pour le moment. Dès que vous commandez (ou recevez une commande), la discussion s'ouvre ici.</p>
                @else
                    <ul role="list" class="msg-items">
                        @foreach ($echanges as $e)
                            <li>
                                <a href="{{ $e['url'] }}" @class(['msg-item', 'msg-item-actif' => $courante && $courante['echange']['commande_id'] === $e['commande_id']])>
                                    <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-full bg-deep text-sm font-semibold">{{ $lettres($e['autre']) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="msg-item-haut">
                                            <span @class(['msg-item-nom', 'msg-item-nom-fort' => $e['non_lus'] > 0])>{{ $e['autre']['nom'] }}</span>
                                            @if ($e['a_des_messages'])<time class="msg-item-date" datetime="{{ $e['date'] }}">{{ \Illuminate\Support\Carbon::parse($e['date'])->diffForHumans() }}</time>@endif
                                        </span>
                                        <span class="msg-item-sujet">{{ $e['titre'] }}</span>
                                        <span @class(['msg-item-apercu', 'msg-item-apercu-fort' => $e['non_lus'] > 0])>
                                            @if ($e['a_des_messages']){{ $e['apercu_moi'] ? 'Vous : ' : '' }}{{ $e['apercu'] }}@else Aucun message pour le moment @endif
                                        </span>
                                    </span>
                                    @if ($e['non_lus'] > 0)<span class="msg-pastille">{{ $e['non_lus'] }}</span>@endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </aside>

            <section class="msg-fil" aria-label="Discussion">
                @if ($courante)
                    @php($ech = $courante['echange'])
                    <header class="msg-entete">
                        <a class="msg-retour" href="{{ $urls['liste'] }}" aria-label="Retour à la liste des discussions"><x-icone nom="chevron-gauche" taille="size-5" /></a>
                        <div class="min-w-0 flex-1">
                            <p class="msg-nom">{{ $ech['autre']['nom'] }} <span class="msg-role">{{ $ech['autre']['role'] }}</span></p>
                            <p class="msg-sujet">{{ $ech['titre'] }}</p>
                        </div>
                        <span class="statut statut-{{ $ech['nuance'] }}">{{ $ech['statut'] }}</span>
                        <a class="btn btn-petit" href="{{ str_replace('__ID__', $ech['commande_id'], $urls['commande']) }}"><span>Voir la commande</span></a>
                    </header>

                    <div class="msg-defilement" role="log" aria-label="Messages de la discussion">
                        @forelse ($courante['messages'] as $m)
                            <div class="msg-ligne {{ $m['expediteur_id'] === $moi ? 'msg-ligne-moi' : 'msg-ligne-autre' }}">
                                <div class="msg-bulle {{ $m['expediteur_id'] === $moi ? 'msg-bulle-moi' : 'msg-bulle-autre' }}">
                                    <p class="msg-texte">{{ $m['contenu'] }}</p>
                                    <p class="msg-meta"><time datetime="{{ $m['date'] }}">{{ \Illuminate\Support\Carbon::parse($m['date'])->format('H:i') }}</time>@if ($m['expediteur_id'] === $moi) <span>{{ $m['lu'] ? '· Lu' : '· Envoyé' }}</span>@endif</p>
                                </div>
                            </div>
                        @empty
                            <p class="msg-debut">C'est le début de votre discussion. Écrivez le premier message.</p>
                        @endforelse
                    </div>

                    <form class="msg-composer" method="POST" action="{{ str_replace('__ID__', $ech['commande_id'], $urls['envoyer']) }}">
                        @csrf
                        <div class="msg-saisie">
                            <label for="msg-contenu" class="sr-only">Votre message</label>
                            <textarea id="msg-contenu" name="contenu" rows="2" maxlength="{{ config('koudmain.messagerie.longueur_max') }}" required placeholder="Écrivez votre message…">{{ old('contenu') }}</textarea>
                            <button type="submit" class="msg-envoyer" aria-label="Envoyer le message"><x-icone nom="fleche-droite" taille="size-4" /></button>
                        </div>
                    </form>
                @else
                    <div class="msg-vide-fil">
                        <span class="msg-vide-icone"><x-icone nom="message" taille="size-6" /></span>
                        <p class="font-medium">Choisissez une discussion</p>
                        <p class="text-sm text-soft">Vos messages arrivent ici en direct, sans actualiser la page.</p>
                    </div>
                @endif
            </section>
        </div>
    </x-island>
</x-layouts.espace>
