<?php

namespace App\Services;

use App\Models\Prestation;
use App\Support\RechercheCriteres;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le catalogue : recherche, filtres, tris (reprend l'ancien recherche_service.php).
 *
 * La recherche de texte combine deux mécanismes, tous deux SANS ACCENTS :
 *  - le plein texte français de PostgreSQL (colonne search_vector, index GIN) : « coiffures » trouve « coiffure »,
 *    « réparer » trouve « réparation » ; chaque mot est cherché comme début de mot (« plomb » trouve « plomberie ») ;
 *  - une recherche par fragment (LIKE) sur le titre, le service, la catégorie et le nom du prestataire :
 *    utile pour les mots courts ou coupés.
 * Chaque mot tapé doit être trouvé quelque part (ET entre les mots), pas forcément au même endroit.
 *
 * Seules les prestations actives de prestataires validés apparaissent.
 */
class RechercheService
{
    /** Relations chargées d'avance : sans cela, chaque carte ferait ses propres requêtes (problème « N+1 »). */
    public const RELATIONS = ['prestataire.quartier.ville', 'prestataire.avatar', 'service.categorie', 'medias'];

    public function requete(RechercheCriteres $c): Builder
    {
        // Note moyenne et nombre d'avis, calculés une seule fois pour toutes les prestations.
        $notes = DB::table('avis')
            ->select('prestation_id')
            ->selectRaw('ROUND(AVG(note)::numeric, 2) AS note_moy, COUNT(*) AS nb_avis')
            ->groupBy('prestation_id');

        $requete = Prestation::query()
            ->select('prestations.*', 'n.note_moy', 'n.nb_avis')
            ->join('users as p', 'p.id', '=', 'prestations.prestataire_id')
            ->join('quartiers as q', 'q.id', '=', 'p.quartier_id')
            ->join('villes as v', 'v.id', '=', 'q.ville_id')
            ->join('services as s', 's.id', '=', 'prestations.service_id')
            ->join('categories as c', 'c.id', '=', 's.categorie_id')
            ->leftJoinSub($notes, 'n', 'n.prestation_id', '=', 'prestations.id')
            ->where('prestations.est_active', true)
            ->where('p.est_prestataire', true)
            ->where('p.est_valide', true)
            ->with(self::RELATIONS);

        $jetons = $c->jetons();

        foreach ($jetons as $jeton) {
            $fragment = "%$jeton%";

            $requete->where(function (Builder $q) use ($jeton, $fragment): void {
                $q->whereRaw("prestations.search_vector @@ to_tsquery('french', ?)", [$jeton.':*'])
                    ->orWhereRaw('immutable_unaccent(lower(prestations.titre)) LIKE ?', [$fragment])
                    ->orWhereRaw('immutable_unaccent(lower(s.nom)) LIKE ?', [$fragment])
                    ->orWhereRaw('immutable_unaccent(lower(c.nom)) LIKE ?', [$fragment])
                    ->orWhereRaw("immutable_unaccent(lower(p.prenom || ' ' || p.nom)) LIKE ?", [$fragment]);
            });
        }

        if ($c->categorie > 0) {
            $requete->where('c.id', $c->categorie);
        }

        if ($c->service > 0) {
            $requete->where('s.id', $c->service);
        }

        if ($c->zone !== '') {
            [$type, $id] = explode(':', $c->zone);
            $requete->where($type === 'v' ? 'v.id' : 'q.id', (int) $id);
        }

        if ($c->prixMin !== null) {
            $requete->where('prestations.prix', '>=', $c->prixMin);
        }

        if ($c->prixMax !== null) {
            $requete->where('prestations.prix', '<=', $c->prixMax);
        }

        if ($c->noteMin > 0) {
            $requete->where('n.note_moy', '>=', $c->noteMin);
        }

        if ($c->avecPhoto) {
            $requete->whereExists(fn ($q) => $q->select(DB::raw(1))->from('medias as m')
                ->whereColumn('m.mediable_id', 'prestations.id')
                ->where('m.mediable_type', Prestation::class)
                ->where('m.type', 'photo_prestation'));
        }

        if ($c->prestataire > 0) {
            $requete->where('p.id', $c->prestataire);
        }

        $this->trier($requete, $c, $jetons);

        return $requete;
    }

    public function rechercher(RechercheCriteres $c, ?int $parPage = null): LengthAwarePaginator
    {
        return $this->requete($c)
            ->paginate($parPage ?? (int) config('koudmain.catalogue.par_page'))
            ->withQueryString();
    }

    /** Les dernières prestations publiées (page d'accueil). */
    public function recentes(int $nombre = 6): Collection
    {
        return $this->requete(new RechercheCriteres())->limit($nombre)->get();
    }

    /** Quelques prestations pour l'autocomplétion de la barre de recherche. */
    public function suggestions(string $saisie, int $nombre = 5): Collection
    {
        $criteres = RechercheCriteres::depuis(['q' => $saisie]);

        return $criteres->aUneRecherche() ? $this->requete($criteres)->limit($nombre)->get() : new Collection();
    }

    /** @param list<string> $jetons */
    private function trier(Builder $requete, RechercheCriteres $c, array $jetons): void
    {
        match ($c->tri) {
            'pertinence' => $this->trierParPertinence($requete, $jetons),
            'prix_asc' => $requete->orderBy('prestations.prix')->orderByDesc('prestations.id'),
            'prix_desc' => $requete->orderByDesc('prestations.prix')->orderByDesc('prestations.id'),
            'note' => $requete->orderByRaw('n.note_moy DESC NULLS LAST')->orderByRaw('COALESCE(n.nb_avis, 0) DESC')->orderByDesc('prestations.id'),
            default => $requete->orderByDesc('prestations.created_at')->orderByDesc('prestations.id'),
        };
    }

    /** @param list<string> $jetons */
    private function trierParPertinence(Builder $requete, array $jetons): void
    {
        if ($jetons === []) {
            $requete->orderByDesc('prestations.created_at');

            return;
        }

        $tsquery = implode(' & ', array_map(fn (string $j) => $j.':*', $jetons));
        $phrase = '%'.implode(' ', $jetons).'%';

        // Le score du plein texte, plus un bonus quand la phrase entière figure dans le titre.
        $requete->orderByRaw(
            "(ts_rank(prestations.search_vector, to_tsquery('french', ?)) + CASE WHEN immutable_unaccent(lower(prestations.titre)) LIKE ? THEN 0.5 ELSE 0 END) DESC",
            [$tsquery, $phrase],
        );
        $requete->orderByRaw('COALESCE(n.nb_avis, 0) DESC')->orderByDesc('prestations.created_at')->orderByDesc('prestations.id');
    }
}
