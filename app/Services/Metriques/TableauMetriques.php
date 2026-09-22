<?php

namespace App\Services\Metriques;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres de la page admin « Métriques et santé ». Tout est calculé à partir des vraies tables (commandes, escrows,
 * paiements, retraits, users, avis) : rien n'est inventé ni recopié. Seuls les événements de connexion et les instantanés
 * quotidiens (« etat.* ») viennent de la table `metriques` (voir Enregistreur).
 *
 * Six requêtes au total (la base est distante : ce sont les allers-retours qui coûtent), puis le résultat est gardé quelques
 * minutes en cache (koudmain.metriques.cache_secondes) — la page reste vivante sans marteler la base à chaque rafraîchissement.
 */
class TableauMetriques
{
    /** @return array<string, mixed> */
    public function pour(int $jours, bool $frais = false): array
    {
        $cle = 'metriques.tableau.'.$jours;

        if ($frais) {
            Cache::forget($cle);
        }

        return Cache::remember($cle, (int) config('koudmain.metriques.cache_secondes'), fn () => $this->calculer($jours));
    }

    /** @return array<string, mixed> */
    private function calculer(int $jours): array
    {
        $aujourdhui = CarbonImmutable::now()->startOfDay();
        $debut = $aujourdhui->subDays($jours - 1);
        $precedent = $debut->subDays($jours);

        $k = (array) DB::selectOne(
            "with p as (select ?::timestamp as d, ?::timestamp as pd)
             select
                (select count(*) from users where not est_admin) as comptes,
                (select count(*) from users, p where not est_admin and created_at >= p.d) as comptes_periode,
                (select count(*) from users, p where not est_admin and created_at >= p.pd and created_at < p.d) as comptes_precedent,
                (select count(*) from users where est_prestataire and est_valide) as prestataires_valides,
                (select count(*) from users where est_prestataire and not est_valide and not est_admin) as a_valider,
                (select count(*) from commandes, p where created_at >= p.d) as commandes_periode,
                (select count(*) from commandes, p where created_at >= p.pd and created_at < p.d) as commandes_precedent,
                (select count(*) from commandes, p where created_at >= p.d and statut = 'terminee') as terminees_periode,
                (select count(*) from commandes, p where created_at >= p.d and statut in ('terminee', 'annulee', 'litige')) as cloturees_periode,
                (select count(*) from commandes where statut in ('en_attente', 'acceptee', 'en_cours')) as commandes_ouvertes,
                (select count(*) from commandes where statut = 'litige') as litiges,
                (select coalesce(sum(montant), 0) from escrows where statut in ('bloque', 'litige')) as sequestre,
                (select coalesce(sum(montant), 0) from escrows, p where statut = 'libere' and libere_at >= p.d) as libere_periode,
                (select coalesce(sum(montant), 0) from escrows, p where statut = 'libere' and libere_at >= p.pd and libere_at < p.d) as libere_precedent,
                (select coalesce(sum(montant), 0) from escrows, p where statut = 'rembourse' and rembourse_at >= p.d) as rembourse_periode,
                (select count(*) from retraits where statut = 'en_attente') as retraits_attente,
                (select coalesce(sum(montant), 0) from retraits where statut = 'en_attente') as retraits_montant,
                (select avg(extract(epoch from acceptee_at - created_at)) from commandes, p where created_at >= p.d and acceptee_at is not null) as delai_acceptation,
                (select avg(note) from avis, p where created_at >= p.d) as note_moyenne,
                (select count(*) from avis, p where created_at >= p.d) as nb_avis,
                (select count(*) from paiements, p where created_at >= p.d and statut = 'reussi') as recharges_reussies,
                (select coalesce(sum(montant), 0) from paiements, p where created_at >= p.d and statut = 'reussi') as recharges_montant,
                (select count(*) from paiements, p where created_at >= p.d and statut = 'echoue') as recharges_echouees",
            [$debut, $precedent],
        );

        $lignes = DB::select(
            "select 'commandes' as serie, created_at::date as jour, count(*)::numeric as valeur from commandes where created_at >= ? group by 2
             union all
             select 'comptes', created_at::date, count(*) from users where not est_admin and created_at >= ? group by 2
             union all
             select 'libere', libere_at::date, sum(montant) from escrows where statut = 'libere' and libere_at >= ? group by 2
             union all
             select nom, created_at::date, sum(valeur) from metriques where nom like 'connexion.%' and created_at >= ? group by 1, 2",
            [$debut, $debut, $debut, $debut],
        );

        $series = [];
        $connexions = ['connexion.succes' => 0, 'connexion.echec' => 0, 'connexion.bloquee' => 0];
        foreach ($lignes as $l) {
            $series[$l->serie][substr((string) $l->jour, 0, 10)] = (float) $l->valeur;
            if (isset($connexions[$l->serie])) {
                $connexions[$l->serie] += (int) round((float) $l->valeur);
            }
        }

        $jour = fn (string $serie) => collect(range(0, $jours - 1))->map(function (int $i) use ($debut, $serie, $series): array {
            $date = $debut->addDays($i)->toDateString();

            return ['jour' => $date, 'valeur' => $series[$serie][$date] ?? 0.0];
        })->all();

        $statuts = DB::table('commandes')->where('created_at', '>=', $debut)->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut')->map(fn ($n) => (int) $n)->all();

        $categories = DB::select(
            "select c.nom, count(distinct cp.commande_id) as commandes, coalesce(sum(cp.prix_unitaire * cp.quantite), 0) as montant
             from commande_prestation cp
             join commandes co on co.id = cp.commande_id
             join prestations p on p.id = cp.prestation_id
             join services s on s.id = p.service_id
             join categories c on c.id = s.categorie_id
             where co.created_at >= ? and co.statut <> 'annulee'
             group by c.nom order by commandes desc, montant desc, c.nom limit 5",
            [$debut],
        );

        $prestations = DB::select(
            "select p.id, p.titre, u.prenom || ' ' || u.nom as prestataire, count(distinct cp.commande_id) as commandes
             from commande_prestation cp
             join commandes co on co.id = cp.commande_id
             join prestations p on p.id = cp.prestation_id
             join users u on u.id = p.prestataire_id
             where co.created_at >= ? and co.statut <> 'annulee'
             group by p.id, p.titre, u.prenom, u.nom order by commandes desc, p.titre limit 5",
            [$debut],
        );

        // Premier instantané de la période, pour dire « +14 depuis le 22 août ».
        $departs = DB::select("select distinct on (nom) nom, valeur, created_at from metriques where nom like 'etat.%' and created_at >= ? order by nom, created_at asc", [$debut]);

        return [
            'jours' => $jours,
            'debut' => $debut->toDateString(),
            'calcule_a' => CarbonImmutable::now()->toIso8601String(),
            'k' => array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, $k),
            'commandes_par_jour' => $jour('commandes'),
            'comptes_par_jour' => $jour('comptes'),
            'libere_par_jour' => $jour('libere'),
            'statuts' => $statuts,
            'categories' => array_map(fn ($l) => ['nom' => $l->nom, 'commandes' => (int) $l->commandes, 'montant' => (float) $l->montant], $categories),
            'prestations' => array_map(fn ($l) => ['id' => (int) $l->id, 'titre' => $l->titre, 'prestataire' => $l->prestataire, 'commandes' => (int) $l->commandes], $prestations),
            'connexions' => $connexions,
            'departs' => collect($departs)->mapWithKeys(fn ($l) => [$l->nom => ['valeur' => (float) $l->valeur, 'date' => substr((string) $l->created_at, 0, 10)]])->all(),
        ];
    }
}
