<?php

namespace App\Services\Metriques;

use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Écrit dans la table `metriques` (nom, valeur, date). Deux sortes de lignes :
 *  - des ÉVÉNEMENTS, ajoutés au fil de l'eau (« connexion.echec ») ;
 *  - des INSTANTANÉS, pris chaque nuit (« etat.* ») : l'état de la plateforme ce jour-là, pour tracer une courbe.
 * Ce qui se déduit déjà des tables (commandes, paiements, inscriptions...) n'est PAS copié ici : la page le calcule directement.
 *
 * Liste blanche des noms (on n'écrit pas n'importe quoi en base) ; une métrique ne fait jamais échouer une action.
 */
class Enregistreur
{
    /** @var array<string, string> */
    public const NOMS = [
        'connexion.succes' => 'Connexions réussies',
        'connexion.echec' => 'Connexions échouées',
        'connexion.bloquee' => 'Connexions bloquées (anti force brute)',
        'etat.comptes' => 'Comptes inscrits',
        'etat.prestataires_valides' => 'Prestataires validés',
        'etat.prestations_publiees' => 'Prestations publiées',
        'etat.commandes_ouvertes' => 'Commandes en cours',
        'etat.sequestre_fcfa' => 'Argent en séquestre (FCFA)',
        'etat.litiges' => 'Litiges non tranchés',
    ];

    public function evenement(string $nom, float $valeur = 1): void
    {
        if (! isset(self::NOMS[$nom]) || str_starts_with($nom, 'etat.') || ! is_finite($valeur)) {
            return;
        }

        $this->ecrire($nom, $valeur);
    }

    /**
     * Prend l'instantané du jour (une seule fois par jour, sauf $forcer).
     *
     * @return int nombre de lignes écrites
     */
    public function instantane(bool $forcer = false): int
    {
        $aujourdhui = now()->startOfDay();

        if (! $forcer && DB::table('metriques')->where('nom', 'like', 'etat.%')->where('created_at', '>=', $aujourdhui)->exists()) {
            return 0;
        }

        $l = DB::selectOne(
            "select
                (select count(*) from users where not est_admin) as comptes,
                (select count(*) from users where est_prestataire and est_valide) as prestataires_valides,
                (select count(*) from prestations where est_active) as prestations_publiees,
                (select count(*) from commandes where statut in ('en_attente', 'acceptee', 'en_cours')) as commandes_ouvertes,
                (select coalesce(sum(montant), 0) from escrows where statut in ('bloque', 'litige')) as sequestre_fcfa,
                (select count(*) from commandes where statut = 'litige') as litiges",
        );

        foreach ((array) $l as $cle => $valeur) {
            $this->ecrire('etat.'.$cle, (float) $valeur);
        }

        return count((array) $l);
    }

    private function ecrire(string $nom, float $valeur): void
    {
        try {
            DB::table('metriques')->insert(['nom' => $nom, 'valeur' => round($valeur, 2), 'created_at' => now()]);
        } catch (Throwable $e) {
            Journal::alerte('metrique.echec', ['nom' => $nom, 'message' => $e->getMessage()]);
        }
    }
}
