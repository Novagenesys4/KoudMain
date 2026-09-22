@php
    $poids = intdiv((int) config('koudmain.media.envoi_max_ko'), 1024);
    $peutPresenter = $user->est_prestataire;
@endphp
<x-layouts.espace titre="Mon profil" :recherche="false">
    <x-espace.entete etiquette="Compte" titre="Mon profil" :intro="$user->nom_complet.' · '.$user->quartier->nom.', '.$user->quartier->ville->nom" />

    <section class="max-w-2xl">

        {{-- ------------------------------------------------------------ Informations personnelles --}}
        <div aria-labelledby="titre-identite">
            <h2 id="titre-identite" class="text-xl font-semibold">Mes informations</h2>
            <p class="mt-2 text-sm text-soft">Ces informations servent aux commandes et aux échanges avec l'autre partie. Votre adresse e-mail ({{ $user->email }}) est votre identifiant de connexion : elle ne change pas ici.</p>

            <form method="POST" action="{{ route('compte.identite') }}" novalidate class="mt-5 grid gap-5">
                @csrf
                @method('PUT')
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <x-champ nom="prenom" libelle="Prénom" :valeur="$user->prenom" autocomplete="given-name" maxlength="100" />
                    <x-champ nom="nom" libelle="Nom" :valeur="$user->nom" autocomplete="family-name" maxlength="50" />
                </div>
                <x-champ nom="telephone" libelle="Téléphone" type="tel" :valeur="$user->telephone" autocomplete="tel" inputmode="tel"
                         aide="10 chiffres, par exemple 07 12 34 56 78." />

                <div class="champ">
                    <label for="champ-quartier_id">Quartier</label>
                    <div class="champ-saisie">
                        <select id="champ-quartier_id" name="quartier_id" required
                                @if ($errors->has('quartier_id')) aria-invalid="true" aria-describedby="champ-quartier_id-erreur" @endif>
                            @foreach ($villes as $ville)
                                @if ($ville->quartiers->isNotEmpty())
                                    <optgroup label="{{ $ville->nom }}">
                                        @foreach ($ville->quartiers as $quartier)
                                            <option value="{{ $quartier->id }}" @selected((string) old('quartier_id', $user->quartier_id) === (string) $quartier->id)>{{ $quartier->nom }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    @error('quartier_id')<p id="champ-quartier_id-erreur" class="champ-erreur">{{ $message }}</p>@enderror
                </div>

                <div><x-bouton chargement="Enregistrement…">Enregistrer mes informations</x-bouton></div>
            </form>
        </div>

        {{-- ------------------------------------------------------------ Photo de profil --}}
        <div class="mt-12 border-t border-line pt-8" aria-labelledby="titre-avatar">
            <h2 id="titre-avatar" class="text-xl font-semibold">Photo de profil</h2>
            <div class="mt-5 flex flex-wrap items-center gap-5">
                <x-avatar :utilisateur="$user" taille="size-20 text-2xl" />
                <div class="min-w-0 flex-1">
                    <form method="POST" action="{{ route('compte.avatar') }}" enctype="multipart/form-data" novalidate class="grid gap-3">
                        @csrf
                        <div class="champ">
                            <label for="avatar" class="sr-only">Choisir une photo de profil</label>
                            <input id="avatar" name="avatar" type="file" required accept="image/jpeg,image/png,image/webp" class="champ-fichier"
                                   data-photos data-max="1" data-poids-max="{{ (int) config('koudmain.media.envoi_max_ko') }}"
                                   @if ($errors->has('avatar')) aria-invalid="true" @endif aria-describedby="a-avatar {{ $errors->has('avatar') ? 'e-avatar' : '' }}">
                            <p id="a-avatar" class="champ-aide">JPG, PNG ou WebP, {{ $poids }} Mo maximum. Un visage bien cadré inspire confiance.</p>
                            <p class="champ-erreur" data-photos-erreur role="alert" hidden></p>
                            @if ($errors->has('avatar'))<p id="e-avatar" class="champ-erreur">{{ $errors->first('avatar') }}</p>@endif
                        </div>
                        <div><x-bouton chargement="Envoi…" :plein="false">Enregistrer la photo</x-bouton></div>
                    </form>

                    @if ($user->avatar)
                        <form method="POST" action="{{ route('compte.avatar.supprimer') }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="lien cursor-pointer text-sm">Supprimer ma photo</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------------ Présentation (prestataires) --}}
        @if ($peutPresenter)
            <div class="mt-12 border-t border-line pt-8" aria-labelledby="titre-bio">
                <h2 id="titre-bio" class="text-xl font-semibold">Ma présentation</h2>
                <p class="mt-2 text-sm text-soft">Elle apparaît sur votre profil public et sur vos prestations. Parlez de votre expérience et de votre façon de travailler.</p>

                <form method="POST" action="{{ route('compte.profil.modifier') }}" novalidate class="mt-5 grid gap-5">
                    @csrf
                    @method('PUT')
                    <div class="champ">
                        <label for="bio" class="sr-only">Ma présentation</label>
                        <div class="champ-saisie">
                            <textarea id="bio" name="bio" rows="6" maxlength="600" data-compteur="600"
                                      placeholder="Ex. : Coiffeuse depuis 8 ans à Cocody, spécialiste des tresses et des tissages…"
                                      @if ($errors->has('bio')) aria-invalid="true" @endif aria-describedby="a-bio {{ $errors->has('bio') ? 'e-bio' : '' }}">{{ old('bio', $user->bio) }}</textarea>
                        </div>
                        <p id="a-bio" class="champ-aide">600 caractères au maximum.</p>
                        @if ($errors->has('bio'))<p id="e-bio" class="champ-erreur">{{ $errors->first('bio') }}</p>@endif
                    </div>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                        <x-bouton chargement="Enregistrement…">Enregistrer</x-bouton>
                        @if ($user->est_valide)
                            <a href="{{ route('prestataires.voir', $user) }}" class="lien text-sm">Voir mon profil public</a>
                        @endif
                    </div>
                </form>
            </div>
        @endif

        {{-- ------------------------------------------------------------ Notifications --}}
        <div class="mt-12 border-t border-line pt-8" aria-labelledby="titre-notifs">
            <h2 id="titre-notifs" class="text-xl font-semibold">Notifications</h2>
            <p class="mt-2 text-sm text-soft">Vous êtes toujours prévenu dans KoudMain (la cloche, en direct). Les e-mails s'y ajoutent : nouvelle commande, commande acceptée, paiement reçu, nouveau message si vous n'êtes pas connecté…</p>
            <form method="POST" action="{{ route('compte.notifications') }}" class="mt-5 grid gap-4">
                @csrf
                @method('PUT')
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="hidden" name="notifications_email" value="0">
                    <input type="checkbox" name="notifications_email" value="1" class="mt-1 size-[1.1rem] cursor-pointer accent-amber" @checked($user->notifications_email)>
                    <span><span class="font-medium">Recevoir des e-mails</span><span class="block text-sm text-soft">Envoyés à {{ $user->email }}.</span></span>
                </label>
                <div><x-bouton chargement="Enregistrement…" :plein="false">Enregistrer</x-bouton></div>
            </form>
        </div>

        <div class="mt-12 border-t border-line pt-8">
            <h2 class="text-xl font-semibold">Sécurité</h2>
            <a href="{{ route('compte.mot-de-passe') }}" class="lien mt-3 inline-block">Changer mon mot de passe</a>
        </div>
    </section>
</x-layouts.espace>
