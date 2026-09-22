<?php

namespace App\Support;

use App\Models\Prestation;
use App\Models\User;

/**
 * Met une prestation (et son prestataire) sous la forme attendue par les cartes React et les pages Blade.
 * La prestation doit avoir été chargée avec RechercheService::RELATIONS (sinon Laravel signale un chargement paresseux).
 */
final class Presentateur
{
    private const TEINTES = ['amber', 'teal', 'rose', 'sable'];

    /**
     * @param  list<int>|null  $favoris  identifiants des prestations en favori du client connecté (null : pas de cœur sur les cartes)
     * @return array<string, mixed>
     */
    public static function carte(Prestation $prestation, ?array $favoris = null): array
    {
        $prestataire = $prestation->prestataire;
        $quartier = $prestataire->quartier;
        $service = $prestation->service;
        $photo = $prestation->medias->first();
        $avis = (int) ($prestation->nb_avis ?? 0);

        return [
            'id' => $prestation->id,
            'slug' => $prestation->slug,
            'url' => route('prestations.voir', $prestation),
            'titre' => $prestation->titre,
            'prestataire' => $prestataire->nom_complet,
            'prestataire_url' => route('prestataires.voir', $prestataire),
            'metier' => $service->nom,
            'categorie' => $service->categorie->nom,
            'note' => $avis > 0 ? round((float) $prestation->note_moy, 1) : null,
            'avis' => $avis,
            'quartier' => $quartier->nom,
            'ville' => $quartier->ville->nom,
            'prix' => (int) round((float) $prestation->prix),
            'duree' => Duree::libelle($prestation->duree_minutes),
            'verifie' => (bool) $prestataire->est_valide,
            'photo' => $photo?->url(),
            'avatar' => $prestataire->avatar?->url(),
            'teinte' => self::TEINTES[$service->categorie_id % count(self::TEINTES)],
        ] + ($favoris === null ? [] : [
            'favori' => in_array($prestation->id, $favoris, true),
            'favori_url' => route('client.favoris.basculer', $prestation),
        ]);
    }

    /** Une teinte stable par utilisateur, pour les avatars sans photo. */
    public static function teinte(User $utilisateur): string
    {
        return self::TEINTES[$utilisateur->id % count(self::TEINTES)];
    }
}
