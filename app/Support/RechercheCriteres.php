<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Les critères du catalogue, lus dans l'adresse (?q=plomb&categorie=3&tri=prix_asc...).
 *
 * Une adresse de recherche est une entrée NON FIABLE : on ne renvoie jamais d'erreur 422 pour un filtre
 * mal formé (un lien partagé ne doit pas casser), on ignore simplement la valeur invalide.
 */
final class RechercheCriteres
{
    public const TRIS = [
        'pertinence' => 'Pertinence',
        'recent' => 'Plus récentes',
        'prix_asc' => 'Prix croissant',
        'prix_desc' => 'Prix décroissant',
        'note' => 'Mieux notées',
    ];

    public const NOTES_MIN = [
        '3' => '3 étoiles et plus',
        '4' => '4 étoiles et plus',
        '4.5' => '4,5 étoiles et plus',
    ];

    public function __construct(
        public readonly string $q = '',
        public readonly int $categorie = 0,
        public readonly int $service = 0,
        public readonly string $zone = '',       // "v:12" (toute la ville) ou "q:45" (un quartier)
        public readonly ?int $prixMin = null,
        public readonly ?int $prixMax = null,
        public readonly float $noteMin = 0.0,
        public readonly bool $avecPhoto = false,
        public readonly string $tri = 'recent',
        public readonly int $prestataire = 0,
    ) {
    }

    /** @param array<string, mixed> $entree typiquement $request->query() */
    public static function depuis(array $entree): self
    {
        $q = self::texte($entree['q'] ?? '', 100);

        $prixMin = self::montant($entree['prix_min'] ?? null);
        $prixMax = self::montant($entree['prix_max'] ?? null);
        if ($prixMin !== null && $prixMax !== null && $prixMin > $prixMax) {
            [$prixMin, $prixMax] = [$prixMax, $prixMin];
        }

        $zone = self::texte($entree['zone'] ?? '', 12);
        if (! preg_match('/^[vq]:\d{1,9}$/', $zone)) {
            $zone = '';
        }

        $note = self::texte($entree['note_min'] ?? '', 4);
        $noteMin = array_key_exists($note, self::NOTES_MIN) ? (float) $note : 0.0;

        $tri = self::texte($entree['tri'] ?? '', 12);
        if (! array_key_exists($tri, self::TRIS) || ($tri === 'pertinence' && $q === '')) {
            $tri = $q !== '' ? 'pertinence' : 'recent';
        }

        return new self(
            q: $q,
            categorie: self::identifiant($entree['categorie'] ?? null),
            service: self::identifiant($entree['service'] ?? null),
            zone: $zone,
            prixMin: $prixMin,
            prixMax: $prixMax,
            noteMin: $noteMin,
            avecPhoto: filter_var($entree['photo'] ?? false, FILTER_VALIDATE_BOOL),
            tri: $tri,
            prestataire: self::identifiant($entree['prestataire'] ?? null),
        );
    }

    /**
     * Les mots de la recherche, sans accents, en minuscules, uniquement lettres et chiffres.
     * Cette forme « nettoyée » est la seule qui entre dans les requêtes SQL de recherche
     * (elle ne contient donc aucun caractère spécial de la syntaxe tsquery ou LIKE).
     *
     * @return list<string>
     */
    public function jetons(): array
    {
        $propre = (string) Str::of($this->q)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim();

        if ($propre === '') {
            return [];
        }

        $mots = array_values(array_unique(array_filter(explode(' ', $propre), fn (string $m) => strlen($m) >= 2)));

        return array_slice($mots, 0, 6);
    }

    public function aUneRecherche(): bool
    {
        return $this->jetons() !== [];
    }

    /** Nombre de filtres actifs (hors tri et texte) : affiché sur le bouton « Filtres » du mobile. */
    public function nombreFiltres(): int
    {
        return (int) ($this->categorie > 0)
            + (int) ($this->service > 0)
            + (int) ($this->zone !== '')
            + (int) ($this->prixMin !== null)
            + (int) ($this->prixMax !== null)
            + (int) ($this->noteMin > 0)
            + (int) $this->avecPhoto;
    }

    /**
     * Les paramètres à remettre dans les liens (pagination, retrait d'un filtre).
     *
     * @param  list<string>  $sauf  noms de paramètres à retirer
     * @return array<string, scalar>
     */
    public function versQuery(array $sauf = []): array
    {
        $params = array_filter([
            'q' => $this->q,
            'categorie' => $this->categorie ?: null,
            'service' => $this->service ?: null,
            'zone' => $this->zone,
            'prix_min' => $this->prixMin,
            'prix_max' => $this->prixMax,
            'note_min' => $this->noteMin > 0 ? rtrim(rtrim(number_format($this->noteMin, 1, '.', ''), '0'), '.') : null,
            'photo' => $this->avecPhoto ? 1 : null,
            'prestataire' => $this->prestataire ?: null,
            'tri' => in_array($this->tri, ['recent', 'pertinence'], true) ? null : $this->tri,
        ], fn ($valeur) => $valeur !== null && $valeur !== '');

        return array_diff_key($params, array_flip($sauf));
    }

    private static function texte(mixed $valeur, int $max): string
    {
        return is_scalar($valeur) ? trim(mb_substr((string) $valeur, 0, $max)) : '';
    }

    private static function identifiant(mixed $valeur): int
    {
        return is_scalar($valeur) && preg_match('/^\d{1,9}$/', (string) $valeur) ? (int) $valeur : 0;
    }

    private static function montant(mixed $valeur): ?int
    {
        if (! is_scalar($valeur)) {
            return null;
        }

        // « 15 000 », « 15.000 » ou « 15000 FCFA » -> 15000
        $chiffres = preg_replace('/\D+/', '', (string) $valeur);

        return $chiffres === '' || strlen($chiffres) > 9 ? null : (int) $chiffres;
    }
}
