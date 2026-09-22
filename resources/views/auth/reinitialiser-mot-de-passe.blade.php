<x-layouts.auth titre="Nouveau mot de passe" variante="connexion">
    <p class="etiquette" data-reveal>Réinitialisation</p>
    <h1 class="mt-5 text-[clamp(2.25rem,4vw,3.25rem)]" data-mots>Choisir un nouveau mot de passe</h1>
    <p class="mt-4 text-soft" data-reveal style="--i: 2">Ce lien ne sert qu'une fois et n'est valable qu'un temps limité.</p>

    {{-- L'action reprend l'URL signée telle quelle (signature et expiration comprises) : le middleware « signed:relative »
         la revérifie aussi sur cet envoi. --}}
    <form method="POST" action="{{ url()->full() }}" class="mt-8 grid gap-7" novalidate>
        @csrf

        <x-champ nom="password" libelle="Nouveau mot de passe" type="password" autocomplete="new-password"
                 aide="8 caractères minimum, avec au moins une lettre et un chiffre." autofocus />
        <x-champ nom="password_confirmation" libelle="Confirmez le nouveau mot de passe" type="password" autocomplete="new-password" />

        <div>
            <x-bouton chargement="Enregistrement…">Réinitialiser mon mot de passe</x-bouton>
        </div>
    </form>
</x-layouts.auth>
