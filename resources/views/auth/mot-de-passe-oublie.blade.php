<x-layouts.auth titre="Mot de passe oublié" variante="connexion">
    <p class="etiquette" data-reveal>Mot de passe oublié</p>
    <h1 class="mt-3 text-[clamp(1.9rem,3vw,2.6rem)]" data-mots>Réinitialiser mon mot de passe</h1>
    <p class="mt-4 text-soft" data-reveal style="--i: 2">
        Saisissez l'adresse de votre compte. Si elle correspond à un compte existant, nous vous envoyons un lien pour choisir un nouveau mot de passe.
    </p>

    <form method="POST" action="{{ route('mot-de-passe-oublie.envoyer') }}" class="mt-6 grid gap-5" novalidate>
        @csrf

        <x-champ nom="email" libelle="Adresse e-mail" type="email" autocomplete="email" inputmode="email" autofocus />

        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-bouton chargement="Envoi…">Envoyer le lien</x-bouton>
            <a href="{{ route('connexion') }}" class="lien text-sm">Retour à la connexion</a>
        </div>
    </form>
</x-layouts.auth>
