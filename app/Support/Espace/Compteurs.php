<?php

namespace App\Support\Espace;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Retrait;
use App\Models\User;
use App\Models\Wallet;
use App\Services\MessageService;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres qu'une page d'espace affiche à plusieurs endroits (pastilles du menu, cloche, en-tête, cartes du tableau de bord).
 *
 * Chaque chiffre n'est calculé qu'UNE fois par requête HTTP : sans cela, le menu, le gabarit et le contrôleur posaient chacun
 * la même question à la base (le solde du wallet, le nombre de messages non lus...), et chaque question coûte un aller-retour
 * réseau vers la base — c'est ce qui fait la lenteur quand la base est distante (Supabase).
 *
 * La mémoire est celle de la requête (pas un attribut statique) : deux requêtes successives, ou deux tests, ne partagent rien.
 */
final class Compteurs
{
    /** @template T @param callable(): T $calcul @return T */
    private static function memo(string $cle, callable $calcul): mixed
    {
        $attributs = request()->attributes;

        if (! $attributs->has($cle)) {
            $attributs->set($cle, $calcul());
        }

        return $attributs->get($cle);
    }

    /** Solde du wallet, en unités de la monnaie (0 si l'utilisateur n'a pas encore de wallet). */
    public static function solde(User $user): float
    {
        return self::memo("compteurs.solde.{$user->id}", fn () => (float) (Wallet::query()->where('user_id', $user->id)->value('solde') ?? 0));
    }

    /** Messages reçus et pas encore lus, tous échanges confondus. */
    public static function messagesNonLus(User $user): int
    {
        return self::memo("compteurs.messages.{$user->id}", fn () => app(MessageService::class)->nonLus($user));
    }

    public static function notificationsNonLues(User $user): int
    {
        return self::memo("compteurs.notifications.{$user->id}", fn () => $user->unreadNotifications()->count());
    }

    /**
     * Les commandes d'un client en une seule requête : total, terminées, en attente.
     *
     * @return array{total: int, terminees: int, en_attente: int}
     */
    public static function commandesClient(User $user): array
    {
        return self::memo("compteurs.commandes.client.{$user->id}", function () use ($user) {
            $ligne = Commande::query()->where('client_id', $user->id)->toBase()
                ->selectRaw('count(*) as total, count(*) filter (where statut = ?) as terminees, count(*) filter (where statut = ?) as en_attente', [
                    StatutCommande::Terminee->value,
                    StatutCommande::EnAttente->value,
                ])
                ->first();

            return ['total' => (int) $ligne->total, 'terminees' => (int) $ligne->terminees, 'en_attente' => (int) $ligne->en_attente];
        });
    }

    /** Commandes d'un prestataire qui attendent sa réponse. */
    public static function commandesEnAttentePrestataire(User $user): int
    {
        return self::memo("compteurs.commandes.prestataire.{$user->id}", fn () => Commande::query()
            ->where('prestataire_id', $user->id)->where('statut', StatutCommande::EnAttente->value)->count());
    }

    /**
     * Les prestations d'un prestataire en une requête : total et publiées.
     *
     * @return array{total: int, publiees: int}
     */
    public static function prestations(User $user): array
    {
        return self::memo("compteurs.prestations.{$user->id}", function () use ($user) {
            $ligne = $user->prestations()->toBase()
                ->selectRaw('count(*) as total, count(*) filter (where est_active) as publiees')
                ->first();

            return ['total' => (int) $ligne->total, 'publiees' => (int) $ligne->publiees];
        });
    }

    /**
     * Tous les chiffres de l'administrateur en UNE requête (menu + tableau de bord).
     *
     * @return array{clients: int, prestataires: int, en_attente: int, comptes: int, commandes: int, litiges: int, retraits: int, categories: int, services: int}
     */
    public static function admin(): array
    {
        return self::memo('compteurs.admin', function () {
            $l = DB::selectOne(
                'select
                    (select count(*) from users where est_client) as clients,
                    (select count(*) from users where est_prestataire and est_valide) as prestataires,
                    (select count(*) from users where est_prestataire and not est_valide) as en_attente,
                    (select count(*) from users where not est_admin) as comptes,
                    (select count(*) from commandes) as commandes,
                    (select count(*) from commandes where statut = ?) as litiges,
                    (select count(*) from retraits where statut = ?) as retraits,
                    (select count(*) from categories) as categories,
                    (select count(*) from services) as services',
                [StatutCommande::Litige->value, Retrait::EN_ATTENTE],
            );

            return array_map('intval', (array) $l);
        });
    }
}
