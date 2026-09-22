@use('App\Support\Duree')
@use('App\Support\Format')
@php
    $modification = $prestation->exists;
    $max = config('koudmain.media.photos_par_prestation');
    $poids = intdiv((int) config('koudmain.media.envoi_max_ko'), 1024);
    $bornes = config('koudmain.prestation');
    $erreur = fn (string $champ) => $errors->first($champ);
    $description = fn (string $champ, string $suite = '') => trim(($erreur($champ) ? "e-$champ " : '').$suite);
@endphp
<x-layouts.espace :titre="$modification ? 'Modifier ma prestation' : 'Nouvelle prestation'" :page="$modification ? 'Modifier' : 'Nouvelle prestation'" :recherche="false">
    <x-espace.entete etiquette="Mes prestations" :titre="$modification ? 'Modifier ma prestation' : 'Nouvelle prestation'"
                     :intro="$modification ? null : 'Décrivez ce que vous proposez : elle apparaît aussitôt dans le catalogue.'">
        <a href="{{ route('prestataire.prestations.index') }}" class="btn btn-petit"><x-icone nom="chevron-gauche" taille="size-4" /><span>Mes prestations</span></a>
    </x-espace.entete>

    <section class="max-w-3xl">
        @if ($modification)
            <p class="mb-8 text-soft">
                @if ($prestation->est_active) Publiée dans le catalogue. @else Masquée : les clients ne la voient pas. @endif
                <a href="{{ route('prestations.voir', $prestation) }}" class="lien">Voir la page publique</a>
            </p>
        @endif

        <form method="POST" enctype="multipart/form-data" novalidate class="grid gap-7"
              action="{{ $modification ? route('prestataire.prestations.mettre-a-jour', $prestation) : route('prestataire.prestations.enregistrer') }}">
            @csrf
            @if ($modification) @method('PUT') @endif

            <div class="champ">
                <label for="titre">Titre</label>
                <div class="champ-saisie">
                    <input id="titre" name="titre" type="text" required maxlength="150" value="{{ old('titre', $prestation->titre) }}"
                           placeholder="Ex. : Tresses africaines à domicile" @if ($erreur('titre')) aria-invalid="true" @endif aria-describedby="{{ $description('titre', 'a-titre') }}">
                </div>
                <p id="a-titre" class="champ-aide">Court et précis : c'est ce que le client lit en premier.</p>
                @if ($erreur('titre'))<p id="e-titre" class="champ-erreur">{{ $erreur('titre') }}</p>@endif
            </div>

            <div class="champ">
                <label for="service_id">Service</label>
                <div class="champ-saisie">
                    <select id="service_id" name="service_id" required @if ($erreur('service_id')) aria-invalid="true" @endif aria-describedby="{{ $description('service_id') }}">
                        <option value="">Choisissez un service</option>
                        @foreach ($categories as $categorie)
                            <optgroup label="{{ $categorie->nom }}">
                                @foreach ($categorie->services as $service)
                                    <option value="{{ $service->id }}" @selected((int) old('service_id', $prestation->service_id) === $service->id)>{{ $service->nom }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                @if ($erreur('service_id'))<p id="e-service_id" class="champ-erreur">{{ $erreur('service_id') }}</p>@endif
            </div>

            <div class="grid items-start gap-7 sm:grid-cols-2">
                <div class="champ">
                    <label for="prix">Prix ({{ config('koudmain.devise') }})</label>
                    <div class="champ-saisie">
                        <input id="prix" name="prix" type="text" inputmode="numeric" required autocomplete="off"
                               value="{{ old('prix', $prestation->exists ? (int) round((float) $prestation->prix) : '') }}" placeholder="15000"
                               @if ($erreur('prix')) aria-invalid="true" @endif aria-describedby="{{ $description('prix', 'a-prix') }}">
                    </div>
                    <p id="a-prix" class="champ-aide">Entre {{ Format::montant($bornes['prix_min']) }} et {{ Format::montant($bornes['prix_max']) }} {{ config('koudmain.devise') }}.</p>
                    @if ($erreur('prix'))<p id="e-prix" class="champ-erreur">{{ $erreur('prix') }}</p>@endif
                </div>

                <div class="champ">
                    <label for="duree_minutes">Durée estimée <span class="font-normal text-faint">(facultatif)</span></label>
                    <div class="champ-saisie">
                        <select id="duree_minutes" name="duree_minutes" @if ($erreur('duree_minutes')) aria-invalid="true" @endif aria-describedby="{{ $description('duree_minutes') }}">
                            <option value="">Non précisée</option>
                            @foreach ($bornes['durees'] as $minutes)
                                <option value="{{ $minutes }}" @selected((int) old('duree_minutes', $prestation->duree_minutes) === $minutes)>{{ Duree::libelle($minutes) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($erreur('duree_minutes'))<p id="e-duree_minutes" class="champ-erreur">{{ $erreur('duree_minutes') }}</p>@endif
                </div>
            </div>

            <div class="champ">
                <label for="description">Description <span class="font-normal text-faint">(facultatif)</span></label>
                <div class="champ-saisie">
                    <textarea id="description" name="description" rows="7" maxlength="5000" placeholder="Ce qui est inclus, le matériel utilisé, vos horaires habituels…"
                              @if ($erreur('description')) aria-invalid="true" @endif aria-describedby="{{ $description('description') }}">{{ old('description', $prestation->description) }}</textarea>
                </div>
                @if ($erreur('description'))<p id="e-description" class="champ-erreur">{{ $erreur('description') }}</p>@endif
            </div>

            @unless ($modification)
                <div class="champ">
                    <label for="photos">Photos <span class="font-normal text-faint">(facultatif, {{ $max }} maximum)</span></label>
                    <input id="photos" name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp" class="champ-fichier"
                           data-photos data-max="{{ $max }}" data-poids-max="{{ (int) config('koudmain.media.envoi_max_ko') }}"
                           @if ($errors->has('photos') || $errors->has('photos.*')) aria-invalid="true" @endif aria-describedby="a-photos {{ $errors->has('photos') || $errors->has('photos.*') ? 'e-photos' : '' }}">
                    <p id="a-photos" class="champ-aide">JPG, PNG ou WebP, {{ $poids }} Mo maximum par photo. La première sera la photo principale.</p>
                    <p class="champ-erreur" data-photos-erreur role="alert" hidden></p>
                    @if ($errors->has('photos') || $errors->has('photos.*'))<p id="e-photos" class="champ-erreur">{{ $errors->first('photos') ?: $errors->first('photos.*') }}</p>@endif
                </div>
            @endunless

            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <x-bouton chargement="Enregistrement…">{{ $modification ? 'Enregistrer' : 'Publier la prestation' }}</x-bouton>
                <a href="{{ route('prestataire.prestations.index') }}" class="lien text-sm">Annuler</a>
            </div>
        </form>
    </section>

    @if ($modification)
        {{-- ------------------------------------------------------------ Photos (formulaires séparés : jamais de formulaire dans un formulaire) --}}
        <section class="mt-14 max-w-3xl border-t border-line pt-10" aria-labelledby="titre-photos">
            <h2 id="titre-photos" class="text-xl font-semibold">Photos <span class="font-normal text-soft">({{ $prestation->medias->count() }}/{{ $max }})</span></h2>
            <p class="mt-2 max-w-xl text-sm text-soft">La première photo illustre votre prestation dans le catalogue. Choisissez-en une autre avec « Mettre en avant ».</p>

            @if ($prestation->medias->isNotEmpty())
                <ul class="mt-6 grid gap-5 sm:grid-cols-2 md:grid-cols-3">
                    @foreach ($prestation->medias as $index => $photo)
                        <li>
                            <div class="relative aspect-[4/3] overflow-hidden rounded-2xl border border-line bg-deep">
                                <img src="{{ $photo->url() }}" alt="Photo {{ $index + 1 }} de la prestation" loading="lazy" decoding="async" class="size-full object-cover">
                                @if ($index === 0)<span class="puce absolute left-2 top-2 bg-surface">Principale</span>@endif
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                @if ($index > 0)
                                    <form method="POST" action="{{ route('prestataire.prestations.photos.principale', [$prestation, $photo]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="lien cursor-pointer">Mettre en avant</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('prestataire.prestations.photos.supprimer', [$prestation, $photo]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cursor-pointer text-danger underline underline-offset-4">Supprimer</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($placesRestantes > 0)
                <form method="POST" action="{{ route('prestataire.prestations.photos.ajouter', $prestation) }}" enctype="multipart/form-data" novalidate class="mt-8 grid gap-4">
                    @csrf
                    <div class="champ">
                        <label for="photos">Ajouter des photos <span class="font-normal text-faint">({{ $placesRestantes }} de plus au maximum)</span></label>
                        <input id="photos" name="photos[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" class="champ-fichier"
                               data-photos data-max="{{ $placesRestantes }}" data-poids-max="{{ (int) config('koudmain.media.envoi_max_ko') }}"
                               @if ($errors->has('photos') || $errors->has('photos.*')) aria-invalid="true" @endif aria-describedby="a-photos {{ $errors->has('photos') || $errors->has('photos.*') ? 'e-photos' : '' }}">
                        <p id="a-photos" class="champ-aide">JPG, PNG ou WebP, {{ $poids }} Mo maximum par photo.</p>
                        <p class="champ-erreur" data-photos-erreur role="alert" hidden></p>
                        @if ($errors->has('photos') || $errors->has('photos.*'))<p id="e-photos" class="champ-erreur">{{ $errors->first('photos') ?: $errors->first('photos.*') }}</p>@endif
                    </div>
                    <div><x-bouton chargement="Envoi des photos…" :plein="false">Envoyer</x-bouton></div>
                </form>
            @else
                <p class="mt-6 text-sm text-soft">Vous avez atteint le maximum de {{ $max }} photos. Supprimez-en une pour en ajouter une autre.</p>
            @endif
        </section>

        {{-- ------------------------------------------------------------ Masquer / supprimer --}}
        <section class="mt-14 max-w-3xl border-t border-line pt-10" aria-labelledby="titre-zone">
            <h2 id="titre-zone" class="text-xl font-semibold">Visibilité et suppression</h2>

            <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3">
                <form method="POST" action="{{ route('prestataire.prestations.activation', $prestation) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn"><span>{{ $prestation->est_active ? 'Masquer du catalogue' : 'Publier de nouveau' }}</span></button>
                </form>
                <p class="max-w-md text-sm text-soft">Masquer retire la prestation du catalogue sans rien perdre : vous pouvez la republier à tout moment.</p>
            </div>

            <div class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3">
                <form method="POST" action="{{ route('prestataire.prestations.supprimer', $prestation) }}"
                      data-confirmer="Supprimer définitivement « {{ $prestation->titre }} » ? La prestation et ses photos seront effacées, sans retour possible." data-confirmer-bouton="Oui, supprimer">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger"><span>Supprimer cette prestation</span></button>
                </form>
                <p class="max-w-md text-sm text-soft">Une prestation déjà commandée ne peut pas être supprimée : masquez-la plutôt.</p>
            </div>
        </section>
    @endif
</x-layouts.espace>
