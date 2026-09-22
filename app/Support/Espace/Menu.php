<?php

namespace App\Support\Espace;

use App\Models\Prestation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Le menu latéral de chaque espace (client, prestataire, administrateur), construit à partir de l'utilisateur
 * et de la route affichée. Même organisation que l'ancienne application : des groupes (Espace personnel,
 * Finance, Compte), des pastilles de comptage et, en bas, le solde du wallet.
 *
 * Forme du résultat :
 *  - espace, racine : « client » et « Espace client » (fil d'Ariane) ;
 *  - groupes : [{titre, liens: [{libelle, icone, href, actif, badge, bientot}]}] ;
 *  - courant : libellé du lien actif (null pour une page qui n'est pas dans le menu) ;
 *  - pied : boîte du bas du menu (wallet ou administration).
 *
 * @phpstan-type Lien array{libelle: string, icone: string, href: string, actif: bool, badge: int|null, bientot: bool, live: string|null}
 */
final class Menu
{
    /**
     * @return array{espace: string, racine: string, groupes: list<array{titre: string, liens: list<array<string, mixed>>}>, courant: string|null, pied: array<string, mixed>}
     */
    public static function pour(User $user, ?string $route): array
    {
        return match ($user->espace()) {
            'admin' => self::admin($route),
            'prestataire' => self::prestataire($user, $route),
            default => self::client($user, $route),
        };
    }

    // ----------------------------------------------------------------- Client

    private static function client(User $user, ?string $route): array
    {
        $commandes = Compteurs::commandesClient($user);
        $enAttente = $commandes['en_attente'];

        $groupes = [
            ['titre' => 'Espace personnel', 'liens' => [
                self::lien($route, 'client.tableau-de-bord', 'Vue d\'ensemble', 'tableau'),
                self::lien($route, 'client.catalogue', 'Catalogue', 'recherche', badge: self::nombrePrestationsVisibles()),
                self::lien($route, 'client.commandes', 'Mes commandes', 'colis', motifs: ['client.commandes*'], badge: $enAttente),
                self::lien($route, 'messages', 'Messages', 'message', motifs: ['messages*'], badge: self::messagesNonLus($user), live: 'messages'),
                self::lien($route, 'client.favoris', 'Mes favoris', 'coeur'),
            ]],
            ['titre' => 'Finance', 'liens' => [
                self::lien($route, 'client.wallet', 'Mon wallet', 'portefeuille'),
            ]],
            self::groupeCompte($route),
        ];

        $total = $commandes['total'];

        return self::assembler('client', 'Espace client', $groupes, [
            'type' => 'wallet',
            'solde' => self::solde($user),
            'href' => route('client.wallet'),
            'progression' => $total > 0 ? (int) round($commandes['terminees'] / $total * 100) : 0,
        ]);
    }

    // ------------------------------------------------------------ Prestataire

    private static function prestataire(User $user, ?string $route): array
    {
        $groupes = [
            ['titre' => 'Espace prestataire', 'liens' => [
                self::lien($route, 'prestataire.tableau-de-bord', 'Vue d\'ensemble', 'tableau'),
                self::lien($route, 'prestataire.prestations.index', 'Mes prestations', 'mallette', motifs: ['prestataire.prestations.*'], badge: Compteurs::prestations($user)['total']),
                self::lien($route, 'prestataire.commandes', 'Commandes', 'liste', motifs: ['prestataire.commandes*'], badge: Compteurs::commandesEnAttentePrestataire($user)),
                self::lien($route, 'messages', 'Messages', 'message', motifs: ['messages*'], badge: self::messagesNonLus($user), live: 'messages'),
                self::lien($route, 'prestataire.disponibilites', 'Disponibilités', 'calendrier'),
            ]],
            ['titre' => 'Finance', 'liens' => [
                self::lien($route, 'prestataire.wallet', 'Mon wallet', 'portefeuille'),
            ]],
            self::groupeCompte($route, [
                ['libelle' => 'Mon profil public', 'icone' => 'oeil', 'href' => route('prestataires.voir', $user), 'actif' => false, 'badge' => null, 'bientot' => false],
            ]),
        ];

        return self::assembler('prestataire', 'Espace prestataire', $groupes, [
            'type' => 'wallet',
            'solde' => self::solde($user),
            'href' => route('prestataire.wallet'),
            'progression' => null,
        ]);
    }

    // ------------------------------------------------------------------ Admin

    private static function admin(?string $route): array
    {
        $chiffres = Compteurs::admin();

        $groupes = [
            ['titre' => 'Administration', 'liens' => [
                self::lien($route, 'admin.tableau-de-bord', 'Tableau de bord', 'tableau'),
                self::lien($route, 'admin.prestataires', 'Prestataires', 'utilisateur-valide', badge: $chiffres['en_attente']),
                self::lien($route, 'admin.utilisateurs', 'Utilisateurs', 'utilisateurs'),
                self::lien($route, 'admin.catalogue', 'Catalogue', 'calques'),
                self::lien($route, 'admin.commandes', 'Commandes', 'liste', motifs: ['admin.commandes*'], badge: $chiffres['litiges']),
                self::lien($route, 'admin.retraits', 'Retraits', 'portefeuille', badge: $chiffres['retraits']),
                self::lien($route, 'admin.metriques', 'Métriques et santé', 'graphique'),
            ]],
            self::groupeCompte($route),
        ];

        return self::assembler('admin', 'Administration', $groupes, [
            'type' => 'admin',
            'comptes' => $chiffres['comptes'],
        ]);
    }

    // ---------------------------------------------------------------- Outils

    /** Le groupe « Compte », commun aux trois espaces (les liens propres à un rôle s'insèrent après le mot de passe). */
    private static function groupeCompte(?string $route, array $supplements = []): array
    {
        return ['titre' => 'Compte', 'liens' => [
            self::lien($route, 'compte.profil', 'Mon profil', 'utilisateur'),
            self::lien($route, 'compte.mot-de-passe', 'Mot de passe', 'cadenas'),
            ...$supplements,
            self::lien($route, 'accueil', 'Voir le site', 'lien-externe'),
        ]];
    }

    /**
     * @param  list<string>|null  $motifs  routes qui rendent ce lien actif (par défaut : sa propre route ; « * » = joker)
     * @return array{libelle: string, icone: string, href: string, actif: bool, badge: int|null, bientot: bool, live: string|null}
     */
    private static function lien(?string $courante, string $route, string $libelle, string $icone, ?array $motifs = null, ?int $badge = null, ?string $live = null): array
    {
        return [
            'libelle' => $libelle,
            'icone' => $icone,
            'href' => route($route),
            'actif' => $courante !== null && Str::is($motifs ?? [$route], $courante),
            'badge' => $badge !== null && $badge > 0 ? $badge : null,
            'bientot' => Bientot::page($route) !== null,
            'live' => $live, // nom de la pastille mise à jour en direct par le navigateur (data-badge)
        ];
    }

    private static function assembler(string $espace, string $racine, array $groupes, array $pied): array
    {
        $courant = null;

        foreach ($groupes as $groupe) {
            foreach ($groupe['liens'] as $lien) {
                if ($lien['actif']) {
                    $courant = $lien['libelle'];
                }
            }
        }

        return compact('espace', 'racine', 'groupes', 'courant', 'pied');
    }

    private static function messagesNonLus(User $user): int
    {
        return Compteurs::messagesNonLus($user);
    }

    private static function solde(User $user): float
    {
        return Compteurs::solde($user);
    }




    /** Nombre d'offres du catalogue (pastille du menu) : mis en cache 5 minutes, il n'a pas besoin d'être exact à la seconde. */
    private static function nombrePrestationsVisibles(): int
    {
        return (int) Cache::remember('espace.catalogue.nombre', 300, fn () => Prestation::query()->visibles()->count());
    }
}
