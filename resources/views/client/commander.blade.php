@use('App\Support\Format')
@php
    $zone = $lieu ? $lieu->nom.', '.$lieu->ville->nom : '';
    $jourMin = now()->addHours((int) config('koudmain.reservation.delai_minimal_heures'))->format('Y-m-d');
    $jourMax = now()->addDays((int) config('koudmain.reservation.jours'))->format('Y-m-d');
@endphp
<x-layouts.espace titre="Commander" page="Commander" :recherche="false">
    <x-espace.entete etiquette="Nouvelle commande" titre="Réserver" :suite="$prestation->titre"
                     intro="Choisissez le jour et l'heure. Payez en main propre, par Mobile Money ou par carte : dans les deux derniers cas, votre argent est mis de côté en séquestre et le prestataire n'est payé qu'une fois la prestation terminée et confirmée par vous.">
        <a href="{{ route('prestations.voir', $prestation) }}" class="btn btn-petit"><x-icone nom="chevron-gauche" taille="size-4" /><span>Retour à la prestation</span></a>
    </x-espace.entete>

    <div class="mb-8 flex items-center gap-4 rounded-2xl border border-line bg-surface p-4">
        <span class="block size-20 shrink-0 overflow-hidden rounded-xl bg-deep">
            @if ($photo)
                <img src="{{ $photo->url() }}" alt="" class="size-full object-cover">
            @else
                <span class="grid size-full place-items-center text-faint"><x-icone nom="image" taille="size-6" /></span>
            @endif
        </span>
        <div class="min-w-0">
            <p class="truncate font-medium">{{ $prestation->titre }}</p>
            <p class="truncate text-sm text-soft">{{ $prestation->service->nom }} · par {{ $prestataire->nom_complet }}</p>
            <p class="mt-1 text-sm text-faint">{{ Format::fcfa($prestation->prix) }}@if ($duree) · {{ $duree }}@endif · {{ $zone ?: 'Choisissez votre quartier dans votre profil' }}</p>
        </div>
    </div>

    @unless ($lieu)
        <p class="message message-erreur mb-6" role="alert">Indiquez d'abord votre quartier dans <a href="{{ route('compte.profil') }}" class="lien">votre profil</a> : c'est là que le prestataire viendra.</p>
    @endunless

    @if (! $aDesHoraires)
        <p class="message mb-6" role="status">{{ $prestataire->prenom }} n'a pas encore indiqué ses horaires : tous les créneaux entre 07 h et 21 h sont proposés, et il confirmera en acceptant la commande.</p>
    @endif

    <form method="POST" action="{{ route('client.commander.envoyer', $prestation) }}">
        @csrf
        <x-island nom="CommandeFormulaire" :donnees="$donnees + ['jours' => $jours, 'lieu' => $zone]">
            {{-- Version sans JavaScript : les mêmes champs, en plus simple. --}}
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="champ">
                    <label for="quantite">Quantité</label>
                    <div class="champ-saisie"><input id="quantite" name="quantite" type="number" min="1" max="{{ config('koudmain.finance.quantite_max') }}" value="{{ old('quantite', 1) }}" required></div>
                </div>
                <div class="champ">
                    <label for="date">Jour</label>
                    <div class="champ-saisie"><input id="date" name="date" type="date" min="{{ $jourMin }}" max="{{ $jourMax }}" value="{{ old('date') }}" required></div>
                </div>
                <div class="champ">
                    <label for="heure">Heure <span class="font-normal text-faint">(heure ronde, ex. 09:00 ou 09:30)</span></label>
                    <div class="champ-saisie"><input id="heure" name="heure" type="time" step="1800" value="{{ old('heure') }}" required></div>
                </div>
                <div class="champ sm:col-span-2">
                    <label for="precisions">Précisions <span class="font-normal text-faint">(facultatif)</span></label>
                    <div class="champ-saisie"><textarea id="precisions" name="precisions" rows="3" maxlength="500">{{ old('precisions') }}</textarea></div>
                </div>
            </div>
            <fieldset class="mt-5 max-w-sm">
                <legend class="mb-2 text-sm font-medium">Comment voulez-vous payer ?</legend>
                <div class="choix">
                    @foreach ($donnees['modes'] as $mode)
                        <label><input type="radio" name="mode_paiement" value="{{ $mode['cle'] }}" @checked(old('mode_paiement', 'mobile_money') === $mode['cle'])><span>{{ $mode['libelle'] }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            @if (count($donnees['cartes']) > 0)
                <div class="champ mt-5 max-w-sm">
                    <label for="carte_id_simple">Carte utilisée <span class="font-normal text-faint">(si vous payez par carte)</span></label>
                    <div class="champ-saisie">
                        <select id="carte_id_simple" name="carte_id">
                            @foreach ($donnees['cartes'] as $c)
                                <option value="{{ $c['id'] }}" @selected((int) old('carte_id', collect($donnees['cartes'])->firstWhere('principale', true)['id'] ?? 0) === $c['id']) @disabled($c['gelee'])>{{ $c['libelle'] }} · •••• {{ $c['fin'] }}{{ $c['gelee'] ? ' (gelée)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif
            <p class="mt-4 text-sm text-soft">Total : <strong>{{ Format::fcfa($prestation->prix) }}</strong> × quantité. Votre solde : {{ Format::fcfa($donnees['solde']) }}.</p>
            <button type="submit" class="btn btn-plein mt-5" data-chargement="Envoi de la demande…"><span data-libelle>Envoyer la demande</span></button>
        </x-island>

        @foreach (['date', 'heure', 'quantite', 'precisions', 'mode_paiement', 'carte_id'] as $champ)
            @error($champ)<p class="champ-erreur mt-3" role="alert">{{ $message }}</p>@enderror
        @endforeach
    </form>
</x-layouts.espace>
