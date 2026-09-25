<x-layouts.auth titre="Connexion" variante="connexion">
    <p class="etiquette" data-reveal>Bon retour</p>
    <h1 class="mt-3 text-[clamp(1.9rem,3vw,2.6rem)]" data-mots>Connexion</h1>
    <p class="mt-4 text-soft" data-reveal style="--i: 2">Retrouvez vos commandes, vos messages et votre porte-monnaie.</p>

    @if ($errors->has('email'))
        {{-- Erreur générale (identifiants faux, compte en attente, trop de tentatives) : annoncée immédiatement. --}}
        <p id="erreur-connexion" class="message message-erreur mt-6" role="alert">{{ $errors->first('email') }}</p>
    @endif

    @if (session('email_a_confirmer'))
        {{-- Mot de passe juste mais adresse non confirmée : on propose de renvoyer le lien (jamais affiché à un simple curieux). --}}
        <form method="POST" action="{{ route('email.renvoyer.envoyer') }}" class="mt-4">
            @csrf
            <input type="hidden" name="email" value="{{ old('email') }}">
            <button type="submit" class="lien text-sm">Renvoyer l'e-mail de confirmation</button>
        </form>
    @endif

    <form method="POST" action="{{ route('connexion') }}" class="mt-6 grid gap-5" novalidate>
        @csrf

        <div class="champ">
            {{-- Adresse e-mail OU numéro de téléphone vérifié (compte créé sur l'application) : le nom du champ reste « email ». --}}
            <label for="champ-email">Adresse e-mail ou numéro de téléphone</label>
            <div class="champ-saisie">
                <input id="champ-email" name="email" type="text" value="{{ old('email') }}" required autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false" @if ($errors->has('email')) aria-invalid="true" aria-describedby="erreur-connexion" @endif>
            </div>
        </div>

        <x-champ nom="password" libelle="Mot de passe" type="password" autocomplete="current-password" />

        <div class="flex flex-wrap items-center justify-between gap-4">
            <label class="flex cursor-pointer items-center gap-3 text-sm text-soft">
                <input type="checkbox" name="remember" value="1" class="size-4 accent-[var(--accent)]" @checked(old('remember'))>
                Rester connecté sur cet appareil
            </label>
            <a href="{{ route('mot-de-passe-oublie') }}" class="lien text-sm">Mot de passe oublié ?</a>
        </div>

        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-bouton chargement="Connexion…">Se connecter</x-bouton>
            <a href="{{ route('inscription') }}" class="lien text-sm">Pas encore de compte ? S'inscrire</a>
            @if (config('koudmain.securite.confirmation_email'))
                <a href="{{ route('email.renvoyer') }}" class="lien text-sm">E-mail de confirmation non reçu ?</a>
            @endif
        </div>
    </form>
</x-layouts.auth>
