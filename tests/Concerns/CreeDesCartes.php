<?php

namespace Tests\Concerns;

use App\Models\CarteVirtuelle;
use App\Models\User;
use App\Services\CarteVirtuelleService;

/** Des cartes de test : numéros d'essai publics des réseaux (clé de Luhn valide), date d'expiration lointaine (le temps est figé au 21 sept. 2026). */
trait CreeDesCartes
{
    public const VISA = '4242424242424242';

    public const MASTERCARD = '5555555555554444';

    public const AMEX = '378282246310005';

    /** Ce que le formulaire « Ajouter une carte » envoie ; `$surcharges` remplace des champs. */
    protected function formulaireCarte(array $surcharges = []): array
    {
        return array_merge([
            'numero_carte' => self::VISA,
            'expiration' => '12/29',
            'cvv' => '123',
            'prenom' => 'Awa',
            'nom' => 'Koné',
            'adresse' => 'Rue des Jardins, Cocody',
            'ville' => 'Abidjan',
            'pays' => 'Côte d\'Ivoire',
            'libelle' => '',
            'couleur' => 'emerald',
        ], $surcharges);
    }

    /** Ce que le service attend (mêmes données, clés du service). */
    protected function donneesCarte(array $surcharges = []): array
    {
        $f = $this->formulaireCarte();

        return array_merge([
            'numero' => $f['numero_carte'], 'expiration' => $f['expiration'], 'cvv' => $f['cvv'], 'prenom' => $f['prenom'], 'nom' => $f['nom'],
            'adresse' => $f['adresse'], 'ville' => $f['ville'], 'pays' => $f['pays'], 'libelle' => null, 'couleur' => $f['couleur'],
        ], $surcharges);
    }

    /** Ajoute une carte à l'utilisateur (Visa par défaut ; passer un autre numéro pour en avoir plusieurs). */
    protected function uneCarte(User $utilisateur, array $surcharges = []): CarteVirtuelle
    {
        return app(CarteVirtuelleService::class)->ajouter($utilisateur, $this->donneesCarte($surcharges));
    }
}
