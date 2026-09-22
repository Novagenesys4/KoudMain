<x-layouts.auth titre="Créer un compte" variante="inscription">
    <p class="etiquette" data-reveal>Bienvenue</p>
    <h1 class="mt-3 text-[clamp(1.9rem,3vw,2.6rem)]" data-mots>Créer un compte</h1>
    <p class="mt-4 text-soft" data-reveal style="--i: 2">Deux minutes suffisent. Vous pourrez changer de projet plus tard.</p>

    @if ($errors->any())
        <p class="message message-erreur mt-6" role="alert">Certains champs sont à corriger.</p>
    @endif

    {{--
        Inscription en 3 étapes (voir resources/js/inscriptionEtapes.js). Le formulaire reste un formulaire HTML
        normal, valable sans JavaScript : sans lui, les trois blocs restent tous affichés à la suite (comme avant),
        avec un seul bouton d'envoi. Avec JavaScript, une seule étape est visible à la fois, la page ne défile plus,
        et si le serveur renvoie une erreur, on retombe directement sur l'étape à corriger.
    --}}
    <form method="POST" action="{{ route('inscription') }}" class="mt-6 grid gap-5" novalidate data-etapes>
        @csrf

        <div class="flex items-center justify-between gap-3 text-sm text-soft" data-etape-info hidden>
            <span data-etape-libelle aria-live="polite">Étape 1 sur 3</span>
        </div>
        <div class="progres" aria-hidden="true" data-progres-barre><i></i></div>

        {{-- Étape 1 : qui s'inscrit --}}
        <div class="grid gap-5" data-etape>
            <fieldset class="grid gap-3">
                <legend class="champ-legende mb-1">Vous êtes…</legend>
                {{-- Choix segmenté : la pastille glisse d'un choix à l'autre (CSS pur, voir .seg). --}}
                <div class="seg">
                    <label>
                        <input type="radio" name="role" value="client" required
                               @checked(old('role', 'client') === 'client')
                               @if ($errors->has('role')) aria-invalid="true" @endif>
                        <strong>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                            Client
                        </strong>
                        <span>Je cherche un service</span>
                    </label>
                    <label>
                        <input type="radio" name="role" value="prestataire" required
                               @checked(old('role') === 'prestataire')
                               @if ($errors->has('role')) aria-invalid="true" @endif>
                        <strong>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
                            Prestataire
                        </strong>
                        <span>Je propose mes services</span>
                    </label>
                </div>
                @error('role')<p class="champ-erreur">{{ $message }}</p>@enderror
            </fieldset>

            <div class="grid items-start gap-5 sm:grid-cols-2">
                <x-champ nom="prenom" libelle="Prénom" autocomplete="given-name" />
                <x-champ nom="nom" libelle="Nom" autocomplete="family-name" />
            </div>

            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <button type="button" class="btn btn-plein" data-etape-suivant hidden>
                    <span>Suivant</span>
                    <svg class="fleche" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
                <a href="{{ route('connexion') }}" class="lien text-sm">J'ai déjà un compte</a>
            </div>
        </div>

        {{-- Étape 2 : où --}}
        <div class="grid gap-5" data-etape>
            <x-champ nom="telephone" libelle="Téléphone" type="tel" autocomplete="tel" inputmode="tel"
                     aide="10 chiffres, par exemple 07 12 34 56 78." />

            <div class="champ">
                <label for="champ-ville_id">Votre ville</label>
                <div class="champ-saisie">
                    <select id="champ-ville_id" required data-ville-select>
                        <option value="" disabled @selected(! $villeSelectionneeId)>Choisissez dans la liste</option>
                        @foreach ($villes as $ville)
                            <option value="{{ $ville->id }}" @selected($villeSelectionneeId === $ville->id)>{{ $ville->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="champ">
                <label for="champ-quartier_id">Votre quartier</label>
                <div class="champ-saisie">
                    {{-- Sans JavaScript, la liste reste groupée par ville, complète et fonctionnelle (comme avant).
                         Avec JavaScript, seul le groupe de la ville choisie ci-dessus reste visible (voir data-ville-id). --}}
                    <select id="champ-quartier_id" name="quartier_id" required
                            @if ($errors->has('quartier_id')) aria-invalid="true" aria-describedby="champ-quartier_id-erreur" @endif>
                        <option value="" disabled @selected(! old('quartier_id'))>
                            {{ $villeSelectionneeId ? 'Choisissez un quartier' : "Choisissez d'abord une ville" }}
                        </option>
                        @foreach ($villes as $ville)
                            @if ($ville->quartiers->isNotEmpty())
                                <optgroup label="{{ $ville->nom }}" data-ville-id="{{ $ville->id }}">
                                    @foreach ($ville->quartiers as $quartier)
                                        <option value="{{ $quartier->id }}" @selected((string) old('quartier_id') === (string) $quartier->id)>{{ $quartier->nom }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                </div>
                @error('quartier_id')<p id="champ-quartier_id-erreur" class="champ-erreur">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <button type="button" class="btn" data-etape-precedent hidden>Précédent</button>
                <button type="button" class="btn btn-plein" data-etape-suivant hidden>
                    <span>Suivant</span>
                    <svg class="fleche" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
                <a href="{{ route('connexion') }}" class="lien text-sm">J'ai déjà un compte</a>
            </div>
        </div>

        {{-- Étape 3 : identifiants --}}
        <div class="grid gap-5" data-etape>
            <x-champ nom="email" libelle="Adresse e-mail" type="email" autocomplete="email" inputmode="email" />
            <x-champ nom="password" libelle="Mot de passe" type="password" autocomplete="new-password"
                     aide="8 caractères minimum, avec au moins une lettre et un chiffre." />
            <x-champ nom="password_confirmation" libelle="Confirmez le mot de passe" type="password" autocomplete="new-password" />

            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <button type="button" class="btn" data-etape-precedent hidden>Précédent</button>
                <x-bouton chargement="Création du compte…">Créer mon compte</x-bouton>
                <a href="{{ route('connexion') }}" class="lien text-sm">J'ai déjà un compte</a>
            </div>
        </div>
    </form>
</x-layouts.auth>
