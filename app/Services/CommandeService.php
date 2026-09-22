<?php

namespace App\Services;

use App\Enums\ActionCommande;
use App\Enums\ModePaiement;
use App\Enums\StatutCommande;
use App\Events\CommandeChangee;
use App\Events\CommandePassee;
use App\Exceptions\OperationRefusee;
use App\Models\Commande;
use App\Models\Escrow;
use App\Models\Prestation;
use App\Models\Quartier;
use App\Models\User;
use App\Support\Argent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La vie d'une commande, et l'argent qui la suit (reprend l'ancien commande_service.php).
 *
 *  - commander()          : selon le mode de paiement, le montant quitte le wallet du client et reste BLOQUÉ (séquestre),
 *                           ou reste à régler en main propre (paiement physique, aucun séquestre) ;
 *  - agir()               : accepter, démarrer, terminer, annuler, signaler un problème, confirmer la réception ;
 *  - arbitrer()           : l'administrateur tranche un litige ;
 *  - libererExpirees()    : sans réponse du client 3 jours après la fin, le prestataire est payé.
 *
 * Garanties : chaque changement d'état et le mouvement d'argent qui va avec se font dans UNE transaction (jamais
 * « annulée mais pas remboursée ») ; le statut est relu sous verrou (un double clic ne fait rien de plus) ; chaque
 * mouvement du séquestre est idempotent ; seule la table de transitions de ActionCommande est acceptée.
 */
class CommandeService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly DisponibiliteService $disponibilites,
        private readonly CarteVirtuelleService $cartes,
    ) {
    }

    // ------------------------------------------------------------ Commander

    /**
     * @param  ModePaiement|null  $mode  physique (en main propre, sans séquestre), Mobile Money ou carte (prélevé sur le wallet, bloqué en séquestre) ;
     *                                     par défaut Mobile Money
     *
     * @throws OperationRefusee message prêt à afficher (dont SoldeInsuffisant)
     */
    public function commander(User $client, Prestation $prestation, int $quantite, ?CarbonInterface $debut, ?string $precisions = null, ?int $quartierId = null, ?int $carteId = null, ?ModePaiement $mode = null): Commande
    {
        $mode ??= ModePaiement::MobileMoney;

        if (! $client->aLeRole('client')) {
            throw new OperationRefusee('Seuls les comptes clients peuvent commander.');
        }

        $prestation->loadMissing('prestataire');
        $prestataire = $prestation->prestataire;

        if (! $prestation->est_active || ! $prestataire->aLeRole('prestataire')) {
            throw new OperationRefusee('Cette prestation n\'est plus disponible.');
        }

        if ($prestataire->id === $client->id) {
            throw new OperationRefusee('Vous ne pouvez pas commander votre propre prestation.');
        }

        $maxQuantite = (int) config('koudmain.finance.quantite_max');

        if ($quantite < 1 || $quantite > $maxQuantite) {
            throw new OperationRefusee("La quantité doit être comprise entre 1 et $maxQuantite.");
        }

        $quartierId ??= $client->quartier_id;

        if (! Quartier::query()->whereKey($quartierId)->exists()) {
            throw new OperationRefusee('Choisissez le quartier où la prestation aura lieu.');
        }

        if ($debut === null) {
            throw new OperationRefusee('Choisissez le jour et l\'heure de la prestation.');
        }

        $duree = ($prestation->duree_minutes ?? 60) * $quantite;

        if ($motif = $this->disponibilites->verifier($prestataire, $debut, $duree)) {
            throw new OperationRefusee($motif);
        }

        // Paiement par carte : la carte choisie (par défaut la carte par défaut) doit être à vous et ne pas être gelée.
        $carte = $mode === ModePaiement::Carte ? $this->cartes->utilisable($client, $carteId) : null;

        $montant = Argent::centimes($prestation->prix) * $quantite;

        if ($montant <= 0) {
            throw new OperationRefusee('Cette prestation n\'a pas de prix valide.');
        }

        $commande = DB::transaction(function () use ($client, $prestation, $prestataire, $quantite, $debut, $precisions, $quartierId, $montant, $duree, $carte, $mode): Commande {
            $commande = new Commande([
                'client_id' => $client->id,
                'quartier_id' => $quartierId,
                'montant_total' => Argent::decimal($montant),
                'date_souhaitee' => $debut,
                'duree_minutes' => min($duree, 65535),
                'precisions' => $precisions !== null && trim($precisions) !== '' ? mb_substr(trim($precisions), 0, 500) : null,
            ]);
            // Le prestataire vient de la prestation (jamais du navigateur) ; le statut de départ est fixé ici.
            $commande->forceFill(['prestataire_id' => $prestataire->id, 'statut' => StatutCommande::EnAttente->value, 'mode_paiement' => $mode->value])->save();
            $commande->prestations()->attach($prestation->id, ['prix_unitaire' => Argent::decimal(Argent::centimes($prestation->prix)), 'quantite' => $quantite]);

            // Paiement physique : rien ne quitte le wallet et aucun séquestre n'est créé, le client paiera le prestataire en main propre.
            if ($mode->passeParLeWallet()) {
                // Débit d'abord : si le solde est insuffisant, tout est annulé (SoldeInsuffisant) et rien n'a été écrit.
                $this->wallets->mouvement($client, 'debit', $montant / 100, "Montant bloqué pour la commande #{$commande->id}", $commande->id, $carte?->id);

                $escrow = new Escrow();
                $escrow->forceFill([
                    'commande_id' => $commande->id,
                    'client_id' => $client->id,
                    'prestataire_id' => $prestataire->id,
                    'montant' => Argent::decimal($montant),
                    'statut' => Escrow::BLOQUE,
                ])->save();
            }

            Log::info('commande.creee', ['commande' => $commande->id, 'client' => $client->id, 'prestataire' => $prestataire->id, 'montant' => $montant / 100, 'mode' => $mode->value]);

            DB::afterCommit(fn () => event(new CommandePassee($commande)));

            return $commande;
        });

        return $commande;
    }

    // ---------------------------------------------------------------- Agir

    /**
     * @throws OperationRefusee
     */
    public function agir(Commande $commande, User $acteur, ActionCommande $action, ?string $motif = null): Commande
    {
        $motif = $motif !== null ? trim($motif) : null;

        if ($action->motifObligatoire() && ($motif === null || mb_strlen($motif) < 10)) {
            throw new OperationRefusee('Décrivez le problème en quelques mots (10 caractères au minimum) : un administrateur en tiendra compte pour trancher.');
        }

        return DB::transaction(function () use ($commande, $acteur, $action, $motif): Commande {
            // Le statut est relu SOUS VERROU : deux clics simultanés ne peuvent pas jouer la même transition deux fois.
            $c = Commande::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();

            if (! $action->autorisePour($acteur, $c)) {
                throw new OperationRefusee('Vous ne pouvez pas effectuer cette action sur cette commande.');
            }

            if (! in_array($c->statut, $action->depuis(), true)) {
                throw new OperationRefusee("Cette commande est « {$c->statut->libelle()} » : l'action « {$action->libelle()} » n'est plus possible.");
            }

            $maintenant = now();

            switch ($action) {
                case ActionCommande::Accepter:
                    if ($c->date_souhaitee !== null) {
                        if ($c->date_souhaitee->isPast()) {
                            throw new OperationRefusee('Le créneau demandé est déjà passé : vous pouvez seulement annuler cette commande.');
                        }

                        // Le créneau a pu être pris par une autre commande acceptée depuis la demande. On verrouille le prestataire
                        // pour que deux acceptations simultanées de créneaux qui se chevauchent passent l'une après l'autre.
                        User::query()->whereKey($c->prestataire_id)->lockForUpdate()->first();

                        if ($this->disponibilites->estOccupe($c->prestataire()->firstOrFail(), $c->date_souhaitee, $c->duree_minutes ?? 60, $c->id)) {
                            throw new OperationRefusee('Ce créneau est déjà pris par une autre de vos commandes acceptées. Refusez cette demande ou libérez l\'autre créneau.');
                        }
                    }
                    $c->forceFill(['statut' => StatutCommande::Acceptee->value, 'acceptee_at' => $maintenant]);
                    break;

                case ActionCommande::Demarrer:
                    $c->forceFill(['statut' => StatutCommande::EnCours->value, 'debut_at' => $maintenant]);
                    break;

                case ActionCommande::Terminer:
                    $c->forceFill(['statut' => StatutCommande::Terminee->value, 'terminee_at' => $maintenant]);
                    break;

                case ActionCommande::Annuler:
                    $c->forceFill(['statut' => StatutCommande::Annulee->value, 'annulee_at' => $maintenant, 'motif_annulation' => $this->motifAnnulation($c, $acteur, $motif)]);
                    $this->rembourser($c);
                    break;

                case ActionCommande::OuvrirLitige:
                    if ($c->statut === StatutCommande::Terminee && $c->validee_client_at !== null) {
                        throw new OperationRefusee('Vous avez déjà confirmé la réception : le prestataire a été payé.');
                    }
                    $c->forceFill(['statut' => StatutCommande::Litige->value, 'motif_litige' => mb_substr((string) $motif, 0, 2000)]);
                    $this->mettreEnLitige($c);
                    break;

                case ActionCommande::ConfirmerReception:
                    if ($c->validee_client_at !== null) {
                        throw new OperationRefusee('La réception de cette commande est déjà confirmée.');
                    }
                    // Le statut reste « Terminée » ; terminee_at n'est pas écrasé (il date la fin de la prestation).
                    $c->forceFill(['validee_client_at' => $maintenant]);
                    $this->liberer($c);
                    break;
            }

            $c->save();

            Log::info('commande.'.$action->value, ['commande' => $c->id, 'acteur' => $acteur->id, 'statut' => $c->statut->value]);

            DB::afterCommit(fn () => event(new CommandeChangee($c, $action->value, $acteur)));

            return $c;
        });
    }

    // ------------------------------------------------------------ Arbitrage

    /**
     * L'administrateur tranche un litige : soit le prestataire est payé (commande terminée), soit le client est remboursé.
     *
     * @throws OperationRefusee
     */
    public function arbitrer(Commande $commande, User $admin, bool $payerLePrestataire, ?string $note = null): Commande
    {
        if (! $admin->aLeRole('admin')) {
            throw new OperationRefusee('Action réservée aux administrateurs.');
        }

        return DB::transaction(function () use ($commande, $admin, $payerLePrestataire, $note): Commande {
            $c = Commande::query()->whereKey($commande->id)->lockForUpdate()->firstOrFail();

            if ($c->statut !== StatutCommande::Litige) {
                throw new OperationRefusee('Cette commande n\'est pas en litige (elle a peut-être déjà été tranchée).');
            }

            $note = $note !== null && trim($note) !== '' ? ' : '.trim($note) : '';
            $maintenant = now();

            if ($payerLePrestataire) {
                $c->forceFill(['statut' => StatutCommande::Terminee->value, 'terminee_at' => $c->terminee_at ?? $maintenant, 'validee_client_at' => $maintenant]);
                $this->liberer($c);
            } else {
                $c->forceFill(['statut' => StatutCommande::Annulee->value, 'annulee_at' => $maintenant, 'motif_annulation' => mb_substr('Litige tranché par l\'administration en faveur du client'.$note, 0, 2000)]);
                $this->rembourser($c);
            }

            $c->save();

            $evenement = $payerLePrestataire ? 'arbitrage_liberer' : 'arbitrage_rembourser';
            Log::info('commande.'.$evenement, ['commande' => $c->id, 'admin' => $admin->id]);

            DB::afterCommit(fn () => event(new CommandeChangee($c, $evenement, $admin)));

            return $c;
        });
    }

    // ------------------------------------------- Libération automatique

    /**
     * Tâche planifiée : une prestation terminée depuis plus de N jours, sans confirmation ni litige du client,
     * est considérée comme reçue et le prestataire est payé. Chaque commande a sa propre transaction :
     * l'échec de l'une n'empêche pas les autres.
     *
     * @return int nombre de commandes libérées
     */
    public function libererExpirees(?int $jours = null): int
    {
        $jours ??= (int) config('koudmain.finance.liberation_auto_jours');
        $liberees = 0;

        $ids = Commande::query()
            ->where('statut', StatutCommande::Terminee->value)
            ->whereNull('validee_client_at')
            ->where('terminee_at', '<', now()->subDays($jours))
            ->orderBy('terminee_at')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$liberees): void {
                    $c = Commande::query()->whereKey($id)->lockForUpdate()->first();

                    if ($c === null || $c->statut !== StatutCommande::Terminee || $c->validee_client_at !== null) {
                        return; // le client a confirmé ou signalé un problème entre-temps
                    }

                    $c->forceFill(['validee_client_at' => now()])->save();
                    $this->liberer($c);
                    $liberees++;

                    Log::info('commande.liberation_auto', ['commande' => $c->id]);
                    DB::afterCommit(fn () => event(new CommandeChangee($c, 'liberation_auto', null)));
                });
            } catch (\Throwable $e) {
                Log::error('commande.liberation_auto_echec', ['commande' => $id, 'erreur' => $e->getMessage()]);
            }
        }

        return $liberees;
    }

    // ------------------------------------------------------------ Suppression d'un compte

    /**
     * Avant de supprimer un PRESTATAIRE : rend aux clients l'argent encore bloqué (séquestre ou litige) de ses commandes.
     * Sans cela, la suppression en cascade effacerait un séquestre alimenté par le wallet d'un autre.
     * À appeler dans la transaction de suppression ; idempotent (un séquestre déjà rendu n'est plus touché).
     *
     * @return array<int, array{client_id: int, commande_id: int, montant: string}> ce qui a été rendu, par commande
     */
    public function rembourserSequestresDuPrestataire(User $compte): array
    {
        $rendus = [];

        $escrows = Escrow::query()
            ->where('prestataire_id', $compte->id)
            ->where('client_id', '!=', $compte->id)
            ->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])
            ->orderBy('id')
            ->get();

        foreach ($escrows as $escrow) {
            $commande = Commande::query()->findOrFail($escrow->commande_id);
            $this->rembourser($commande);

            $rendus[$commande->id] = ['client_id' => (int) $escrow->client_id, 'commande_id' => $commande->id, 'montant' => (string) $escrow->montant];
            Log::info('commande.remboursee_suppression_compte', ['commande' => $commande->id, 'client' => $escrow->client_id, 'prestataire' => $compte->id, 'montant' => (string) $escrow->montant]);
        }

        return $rendus;
    }

    /**
     * Avant de supprimer un CLIENT : le travail terminé mais pas encore confirmé (séquestre bloqué, commande « terminée »)
     * est payé au prestataire, comme le ferait la libération automatique au bout de quelques jours. Sans cela, le
     * prestataire perdrait le paiement d'une prestation faite. Les commandes non terminées (en attente, acceptée, en
     * cours, litige) ne sont pas payées : l'argent était celui du client, il disparaît avec son wallet.
     * À appeler dans la transaction de suppression ; idempotent.
     *
     * @return array<int, array{prestataire_id: int, commande_id: int, montant: string}> ce qui a été versé, par commande
     */
    public function libererSequestresTerminesDuClient(User $compte): array
    {
        $verses = [];

        $escrows = Escrow::query()
            ->where('client_id', $compte->id)
            ->where('prestataire_id', '!=', $compte->id)
            ->where('statut', Escrow::BLOQUE)
            ->whereIn('commande_id', Commande::query()->where('statut', StatutCommande::Terminee->value)->select('id'))
            ->orderBy('id')
            ->get();

        foreach ($escrows as $escrow) {
            $commande = Commande::query()->findOrFail($escrow->commande_id);
            $this->liberer($commande);

            $verses[$commande->id] = ['prestataire_id' => (int) $escrow->prestataire_id, 'commande_id' => $commande->id, 'montant' => (string) $escrow->montant];
            Log::info('commande.liberee_suppression_compte', ['commande' => $commande->id, 'client' => $compte->id, 'prestataire' => $escrow->prestataire_id, 'montant' => (string) $escrow->montant]);
        }

        return $verses;
    }

    // ------------------------------------------------------------ Séquestre

    /** Le prestataire reçoit l'argent. Sans effet si le séquestre n'est plus bloqué (idempotent). */
    private function liberer(Commande $commande): void
    {
        $escrow = Escrow::query()->where('commande_id', $commande->id)->lockForUpdate()->first();

        if ($escrow === null || ! in_array($escrow->statut, [Escrow::BLOQUE, Escrow::LITIGE], true)) {
            return;
        }

        $prestataire = User::query()->findOrFail($escrow->prestataire_id);
        $this->wallets->mouvement($prestataire, 'credit', $escrow->montant, "Paiement libéré pour la commande #{$commande->id}", $commande->id);

        $escrow->forceFill(['statut' => Escrow::LIBERE, 'libere_at' => now()])->save();
    }

    /** Le client retrouve son argent. Sans effet si le séquestre n'est plus bloqué (idempotent). */
    private function rembourser(Commande $commande): void
    {
        $escrow = Escrow::query()->where('commande_id', $commande->id)->lockForUpdate()->first();

        if ($escrow === null || ! in_array($escrow->statut, [Escrow::BLOQUE, Escrow::LITIGE], true)) {
            return;
        }

        $client = User::query()->findOrFail($escrow->client_id);
        $this->wallets->mouvement($client, 'credit', $escrow->montant, "Remboursement de la commande #{$commande->id}", $commande->id);

        $escrow->forceFill(['statut' => Escrow::REMBOURSE, 'rembourse_at' => now()])->save();
    }

    /** L'argent reste bloqué pendant le litige. */
    private function mettreEnLitige(Commande $commande): void
    {
        Escrow::query()->where('commande_id', $commande->id)->where('statut', Escrow::BLOQUE)->update(['statut' => Escrow::LITIGE]);
    }

    private function motifAnnulation(Commande $commande, User $acteur, ?string $motif): string
    {
        $qui = $acteur->id === $commande->client_id ? 'le client' : 'le prestataire';

        return mb_substr("Annulée par $qui".($motif ? ' : '.$motif : ''), 0, 2000);
    }
}
