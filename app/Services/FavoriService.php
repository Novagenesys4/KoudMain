<?php

namespace App\Services;

use App\Models\Prestation;
use App\Models\User;
use App\Support\RechercheCriteres;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Les favoris d'un client : les prestations qu'il veut retrouver. Un favori = une ligne (client, prestation), jamais deux.
 * Une prestation qui n'est plus visible (masquée, prestataire suspendu) reste enregistrée mais n'apparaît plus dans la liste.
 */
class FavoriService
{
    public function __construct(private readonly RechercheService $recherche)
    {
    }

    /** Ajoute ou retire. @return bool vrai si la prestation est maintenant en favori */
    public function basculer(User $utilisateur, Prestation $prestation): bool
    {
        $retires = DB::table('favoris')->where('user_id', $utilisateur->id)->where('prestation_id', $prestation->id)->delete();

        if ($retires > 0) {
            return false;
        }

        // On n'ajoute que ce qu'un visiteur peut réellement voir dans le catalogue.
        $visible = Prestation::query()->visibles()->whereKey($prestation->id)->exists();
        abort_unless($visible && $prestation->prestataire_id !== $utilisateur->id, 404);

        // insertOrIgnore : deux clics quasi simultanés ne provoquent pas d'erreur.
        DB::table('favoris')->insertOrIgnore(['user_id' => $utilisateur->id, 'prestation_id' => $prestation->id, 'created_at' => now()]);

        return true;
    }

    /**
     * Parmi ces prestations, lesquelles sont en favori ? (une seule requête pour toute une page de cartes)
     *
     * @param  list<int>  $prestationIds
     * @return list<int>
     */
    public function parmi(User $utilisateur, array $prestationIds): array
    {
        if ($prestationIds === []) {
            return [];
        }

        return DB::table('favoris')->where('user_id', $utilisateur->id)->whereIn('prestation_id', $prestationIds)->pluck('prestation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Les favoris visibles, le plus récemment ajouté en premier (avec la note moyenne, comme dans le catalogue). */
    public function liste(User $utilisateur, int $parPage = 12): LengthAwarePaginator
    {
        return $this->recherche->requete(new RechercheCriteres())
            ->reorder()
            ->join('favoris as f', fn ($j) => $j->on('f.prestation_id', '=', 'prestations.id')->where('f.user_id', '=', $utilisateur->id))
            ->orderByDesc('f.created_at')->orderByDesc('prestations.id')
            ->paginate($parPage)->withQueryString();
    }

    public function nombre(User $utilisateur): int
    {
        return (int) DB::table('favoris as f')
            ->join('prestations', 'prestations.id', '=', 'f.prestation_id')
            ->join('users as p', 'p.id', '=', 'prestations.prestataire_id')
            ->where('f.user_id', $utilisateur->id)->where('prestations.est_active', true)->where('p.est_prestataire', true)->where('p.est_valide', true)
            ->count();
    }
}
