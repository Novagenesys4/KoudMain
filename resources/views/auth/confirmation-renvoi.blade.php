<x-layouts.auth titre="Confirmer mon adresse" variante="connexion">
    <p class="etiquette" data-reveal>Confirmation</p>
    <h1 class="mt-5 text-[clamp(2.25rem,4vw,3.25rem)]" data-mots>Renvoyer l'e-mail</h1>
    <p class="mt-4 text-soft" data-reveal style="--i: 2">
        Saisissez l'adresse utilisée à l'inscription. Si un compte attend sa confirmation, nous vous renvoyons le lien.
    </p>

    <form method="POST" action="{{ route('email.renvoyer.envoyer') }}" class="mt-8 grid gap-7" novalidate>
        @csrf

        <x-champ nom="email" libelle="Adresse e-mail" type="email" autocomplete="email" inputmode="email" autofocus />

        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-bouton chargement="Envoi…">Renvoyer le lien</x-bouton>
            <a href="{{ route('connexion') }}" class="lien text-sm">Retour à la connexion</a>
        </div>
    </form>
</x-layouts.auth>
