<?php

namespace App\Services;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Escrow;
use App\Models\Retrait;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Media\MediaManager;
use App\Services\Notifications\NotificationService;
use App\Support\Format;
use App\Support\Journal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Actions d'administration sur les comptes : valider, suspendre, supprimer.
 * Les règles de sécurité sont ICI (et non dans la vue) : le bouton masqué n'est jamais une protection.
 */
class UtilisateurService
{
    public function __construct(
        private readonly MediaManager $medias,
        private readonly CommandeService $commandes,
        private readonly NotificationService $notifications,
    ) {
    }

    /** Valide un prestataire inscrit : il peut se connecter et publier. @return string|null message d'erreur */
    public function valider(User $admin, User $cible): ?string
    {
        if (! $cible->est_prestataire || $cible->est_admin) {
            return 'Ce compte n\'est pas un prestataire.';
        }

        if ($cible->est_valide) {
            return 'Ce prestataire est déjà validé.';
        }

        $cible->forceFill(['est_valide' => true])->save();
        Journal::info('admin.prestataire_valide', ['admin' => $admin->id, 'prestataire' => $cible->id]);

        return null;
    }

    /**
     * Retire l'accès d'un prestataire validé (il repasse « à valider » : connexion refusée, offres retirées du catalogue).
     * Rien n'est supprimé : « Valider » le rétablit à l'identique. @return string|null message d'erreur
     */
    public function suspendre(User $admin, User $cible): ?string
    {
        if (! $cible->est_prestataire || $cible->est_admin) {
            return 'Ce compte n\'est pas un prestataire.';
        }

        if (! $cible->est_valide) {
            return 'Ce prestataire n\'est pas validé.';
        }

        $cible->forceFill(['est_valide' => false])->save();
        Journal::info('admin.prestataire_suspendu', ['admin' => $admin->id, 'prestataire' => $cible->id]);

        return null;
    }

    /**
     * Supprime définitivement un compte ET tout ce qui en dépend, en cascade : prestations et photos, commandes (avec
     * séquestres, messages, avis), wallet et cartes, paiements, retraits, notifications, favoris, disponibilités.
     *
     * Une seule protection reste : pas d'administrateur, pas soi-même. L'argent d'AUTRUI n'est jamais perdu : si le
     * compte est un prestataire avec un séquestre encore bloqué, le client est remboursé avant la suppression, et
     * l'autre partie de chaque commande en cours est prévenue. Le journal garde les montants effacés.
     *
     * @return string|null message d'erreur (null = supprimé)
     */
    public function supprimer(User $admin, User $cible): ?string
    {
        if ($cible->is($admin)) {
            return 'Vous ne pouvez pas supprimer votre propre compte.';
        }

        if ($cible->est_admin) {
            return 'Un compte administrateur ne se supprime pas ici.';
        }

        // Ce qui va disparaître : relevé AVANT, pour le journal et pour prévenir les autres parties.
        $commandeIds = $this->commandesLiees($cible);
        $commandes = Commande::query()->whereIn('id', $commandeIds)->get(['id', 'client_id', 'prestataire_id', 'statut']);
        $soldeEfface = (float) Wallet::query()->where('user_id', $cible->id)->value('solde');
        $sequestreEfface = (float) Escrow::query()->where('client_id', $cible->id)->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])->sum('montant');
        $retraitsEnAttente = Retrait::query()->where('user_id', $cible->id)->where('statut', 'en_attente')->count();

        try {
            [$rembourses, $verses] = DB::transaction(function () use ($cible, $commandeIds): array {
                $rembourses = $this->commandes->rembourserSequestresDuPrestataire($cible);
                $verses = $this->commandes->libererSequestresTerminesDuClient($cible);

                // Les commandes d'abord (la base emporte séquestres, messages, avis, lignes) : une prestation déjà commandée
                // ne se supprime pas seule, mais ici tout ce qui la commande part avec elle.
                Commande::query()->whereIn('id', $commandeIds)->delete();

                // Les prestations une par une : les photos (fichiers ET lignes) suivent, ce que la base ne fait pas.
                foreach ($cible->prestations()->get() as $prestation) {
                    $prestation->delete();
                }

                $avatar = $cible->avatar()->first();

                if ($avatar !== null) {
                    $this->medias->supprimer($avatar);
                }

                // Tables sans clé étrangère vers users : à vider à la main.
                $cible->notifications()->delete();
                DB::table('sessions')->where('user_id', $cible->id)->delete();
                // Jetons de l'application mobile (table sans clé étrangère : polymorphe).
                $cible->tokens()->delete();

                $cible->delete();

                return [$rembourses, $verses];
            });
        } catch (QueryException $e) {
            Journal::alerte('admin.suppression_refusee', ['admin' => $admin->id, 'compte' => $cible->id, 'erreur' => $e->getCode(), 'message' => $e->getMessage()]);

            return 'Ce compte n\'a pas pu être supprimé : une donnée liée l\'en empêche. Réessayez ; si cela persiste, consultez le journal.';
        }

        Journal::info('admin.compte_supprime', [
            'admin' => $admin->id,
            'compte' => $cible->id,
            'role' => $cible->est_prestataire ? 'prestataire' : 'client',
            'commandes_supprimees' => $commandeIds->count(),
            'sequestres_rembourses' => count($rembourses),
            'sequestres_verses_au_prestataire' => count($verses),
            'solde_efface' => $soldeEfface,
            'sequestre_client_efface' => $sequestreEfface,
            'retraits_en_attente_effaces' => $retraitsEnAttente,
        ]);

        $this->prevenirLesAutresParties($cible, $commandes, $rembourses, $verses);

        return null;
    }

    /** Toutes les commandes du compte : comme client, comme prestataire, ou contenant l'une de ses prestations. @return Collection<int, int> */
    private function commandesLiees(User $cible): Collection
    {
        $ids = Commande::query()->where('client_id', $cible->id)->orWhere('prestataire_id', $cible->id)->pluck('id');

        $viaPrestations = DB::table('commande_prestation')
            ->join('prestations', 'prestations.id', '=', 'commande_prestation.prestation_id')
            ->where('prestations.prestataire_id', $cible->id)
            ->pluck('commande_prestation.commande_id');

        return $ids->merge($viaPrestations)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Le client (ou le prestataire) d'une commande encore vivante apprend qu'elle a disparu, et ce qui lui est rendu.
     *
     * @param  Collection<int, Commande>  $commandes
     * @param  array<int, array{client_id: int, commande_id: int, montant: string}>  $rembourses
     * @param  array<int, array{prestataire_id: int, commande_id: int, montant: string}>  $verses
     */
    private function prevenirLesAutresParties(User $cible, Collection $commandes, array $rembourses, array $verses): void
    {
        $vivantes = [StatutCommande::EnAttente, StatutCommande::Acceptee, StatutCommande::EnCours, StatutCommande::Litige];
        $lettres = [];

        foreach ($commandes as $commande) {
            $rendu = $rembourses[$commande->id] ?? null;
            $verse = $verses[$commande->id] ?? null;

            if ($rendu === null && $verse === null && ! in_array($commande->statut, $vivantes, true)) {
                continue;
            }

            $autreId = $commande->client_id === $cible->id ? $commande->prestataire_id : $commande->client_id;

            if ($autreId === null || $autreId === $cible->id) {
                continue;
            }

            $lettres[$autreId][] = match (true) {
                $rendu !== null => 'Commande #'.$commande->id.' annulée : '.Format::fcfa($rendu['montant']).' remis sur votre wallet.',
                $verse !== null => 'Commande #'.$commande->id.' terminée : '.Format::fcfa($verse['montant']).' versés sur votre wallet.',
                default => 'Commande #'.$commande->id.' annulée.',
            };
        }

        foreach (User::query()->whereIn('id', array_keys($lettres))->get() as $autre) {
            $this->notifications->envoyer(
                $autre,
                'commande_annulee',
                'Compte supprimé : vos commandes en cours',
                'Le compte de '.($cible->est_prestataire ? 'votre prestataire' : 'votre client').' a été supprimé. '.implode(' ', $lettres[$autre->id]),
                $autre->est_prestataire ? '/prestataire/commandes' : '/client/commandes',
                'interdit',
                email: array_filter($rembourses, fn ($r) => $r['client_id'] === $autre->id) !== [] || array_filter($verses, fn ($v) => $v['prestataire_id'] === $autre->id) !== [],
            );
        }
    }
}
