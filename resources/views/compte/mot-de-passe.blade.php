<x-layouts.espace titre="Mot de passe" :recherche="false">
    <x-espace.entete etiquette="Compte" titre="Mot de passe" intro="Choisissez un mot de passe que vous n'utilisez nulle part ailleurs." />

    <form method="POST" action="{{ route('compte.mot-de-passe.modifier') }}" class="grid max-w-xl gap-7" novalidate>
        @csrf
        @method('PUT')

        <x-champ nom="mot_de_passe_actuel" libelle="Mot de passe actuel" type="password" autocomplete="current-password" />
        <x-champ nom="password" libelle="Nouveau mot de passe" type="password" autocomplete="new-password"
                 aide="8 caractères minimum, avec au moins une lettre et un chiffre." />
        <x-champ nom="password_confirmation" libelle="Confirmez le nouveau mot de passe" type="password" autocomplete="new-password" />

        <div>
            <x-bouton chargement="Enregistrement…">Enregistrer</x-bouton>
        </div>
    </form>
</x-layouts.espace>
