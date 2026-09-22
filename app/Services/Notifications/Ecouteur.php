<?php

namespace App\Services\Notifications;

use App\Events\CommandeChangee;
use App\Events\CommandePassee;
use App\Events\RetraitDemande;
use App\Events\RetraitTraite;
use App\Models\Avis;
use App\Models\Commande;
use App\Models\Retrait;
use App\Models\User;
use App\Services\TempsReel\Diffuseur;
use App\Support\Format;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Le seul endroit qui décide QUI est prévenu de QUOI, et comment (cloche, e-mail, mise à jour en direct des pages).
 * Il écoute les événements du métier (CommandePassee, CommandeChangee, RetraitDemande, RetraitTraite) et les actions
 * d'administration (inscription, validation, avis).
 *
 * Règle d'or : un souci ici (e-mail en panne, base lente) ne doit jamais faire échouer l'action de l'utilisateur.
 * Chaque méthode publique attrape donc toutes les erreurs et les journalise.
 */
class Ecouteur
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Diffuseur $diffuseur,
    ) {
    }

    // ---------------------------------------------------------------- Commandes

    public function commandePassee(CommandePassee $evenement): void
    {
        $this->proteger(function () use ($evenement): void {
            $commande = $this->charger($evenement->commande);
            $titre = $this->titre($commande);
            $quand = $commande->date_souhaitee ? Str::ucfirst($commande->date_souhaitee->translatedFormat('l j F \à H:i')) : 'une date à convenir';

            $this->notifications->envoyer(
                $commande->prestataire, 'commande_nouvelle', 'Nouvelle commande',
                "{$commande->client->prenom} a commandé « $titre » ({$this->montant($commande)}) pour le $quand".($commande->payeeEnPhysique() ? ', à régler en main propre' : '').". Acceptez-la pour réserver le créneau.",
                "/prestataire/commandes/{$commande->id}", 'colis', email: true, bouton: 'Voir la commande',
            );

            $this->rafraichirCommande($commande);
        });
    }

    public function commandeChangee(CommandeChangee $evenement): void
    {
        $this->proteger(function () use ($evenement): void {
            $commande = $this->charger($evenement->commande);
            $titre = $this->titre($commande);
            $client = $commande->client;
            $prestataire = $commande->prestataire;
            $urlClient = "/client/commandes/{$commande->id}";
            $urlPrestataire = "/prestataire/commandes/{$commande->id}";
            $acteur = $evenement->acteur;

            switch ($evenement->evenement) {
                case 'accepter':
                    $quand = $commande->date_souhaitee ? ' Rendez-vous le '.$commande->date_souhaitee->translatedFormat('l j F \à H:i').'.' : '';
                    $this->notifications->envoyer($client, 'commande_acceptee', 'Commande acceptée', "{$prestataire->prenom} a accepté « $titre ».$quand", $urlClient, 'coche', email: true, bouton: 'Voir la commande');
                    break;

                case 'demarrer':
                    $this->notifications->envoyer($client, 'commande_demarree', 'Prestation démarrée', "{$prestataire->prenom} a commencé « $titre ».", $urlClient, 'horloge');
                    break;

                case 'terminer':
                    $this->notifications->envoyer($client, 'commande_terminee', 'Prestation terminée', "{$prestataire->prenom} a terminé « $titre ». Confirmez la réception pour le payer, ou signalez un problème.", $urlClient, 'coche', email: true, bouton: 'Confirmer ou signaler');
                    break;

                case 'annuler':
                    // L'autre partie est prévenue ; l'annulation automatique (sans acteur) prévient les deux.
                    $parClient = $acteur?->is($client) ?? false;
                    if (! $parClient) {
                        $this->notifications->envoyer($client, 'commande_annulee', 'Commande annulée', "« $titre » a été annulée.".($commande->payeeEnPhysique() ? '' : " Les {$this->montant($commande)} bloqués vous ont été remboursés."), $urlClient, 'interdit', email: true, bouton: 'Voir la commande');
                    }
                    if ($acteur === null || $parClient) {
                        $this->notifications->envoyer($prestataire, 'commande_annulee', 'Commande annulée', "{$client->prenom} a annulé « $titre ».", $urlPrestataire, 'interdit', email: true, bouton: 'Voir la commande');
                    }
                    break;

                case 'ouvrir_litige':
                    $this->notifications->envoyer($prestataire, 'commande_litige', 'Problème signalé', "{$client->prenom} signale un problème sur « $titre ». ".($commande->payeeEnPhysique() ? "Un administrateur va examiner la situation." : "L'argent reste bloqué jusqu'à la décision d'un administrateur."), $urlPrestataire, 'interdit', email: true, bouton: 'Voir la commande');
                    $this->notifications->envoyerA($this->administrateurs(), 'admin_litige', 'Litige à arbitrer', "Commande n° {$commande->id} : « $titre » ({$this->montant($commande)}).", "/admin/commandes/{$commande->id}", 'bouclier');
                    break;

                case 'confirmer_reception':
                case 'liberation_auto':
                    $auto = $evenement->evenement === 'liberation_auto';
                    if ($commande->payeeEnPhysique()) {
                        $this->notifications->envoyer($prestataire, 'commande_confirmee', 'Commande confirmée', ($auto ? "Le client n'a pas répondu : " : "{$client->prenom} a confirmé la réception : ")."« $titre » est terminée (règlement en main propre, {$this->montant($commande)}).", $urlPrestataire, 'coche', email: true, bouton: 'Voir la commande');
                    } else {
                        $this->notifications->envoyer($prestataire, 'paiement_recu', 'Paiement reçu', ($auto ? "Le client n'a pas répondu : " : "{$client->prenom} a confirmé la réception : ")."{$this->montant($commande)} pour « $titre » sont sur votre wallet.", '/prestataire/wallet', 'portefeuille', email: true, bouton: 'Voir mon wallet');
                    }
                    $this->demandeDeNote($commande, $auto ? "Le paiement de {$prestataire->prenom} a été libéré." : 'Merci !');
                    break;

                case 'arbitrage_liberer':
                    $this->notifications->envoyer($prestataire, 'litige_tranche', 'Litige tranché en votre faveur', $commande->payeeEnPhysique() ? "« $titre » est validée : le règlement se fait en main propre." : "Les {$this->montant($commande)} de « $titre » ont été versés sur votre wallet.", $commande->payeeEnPhysique() ? $urlPrestataire : '/prestataire/wallet', 'portefeuille', email: true, bouton: 'Voir mon wallet');
                    $this->notifications->envoyer($client, 'litige_tranche', 'Litige tranché', "Un administrateur a décidé de payer {$prestataire->prenom} pour « $titre ».", $urlClient, 'bouclier', email: true, bouton: 'Voir la commande');
                    break;

                case 'arbitrage_rembourser':
                    $this->notifications->envoyer($client, 'litige_tranche', 'Litige tranché en votre faveur', $commande->payeeEnPhysique() ? "« $titre » est annulée : vous ne devez rien au prestataire." : "Les {$this->montant($commande)} de « $titre » ont été remboursés sur votre wallet.", $commande->payeeEnPhysique() ? $urlClient : '/client/wallet', 'portefeuille', email: true, bouton: 'Voir mon wallet');
                    $this->notifications->envoyer($prestataire, 'litige_tranche', 'Litige tranché', "Un administrateur a décidé de rembourser {$client->prenom} pour « $titre ».", $urlPrestataire, 'bouclier', email: true, bouton: 'Voir la commande');
                    break;
            }

            $this->rafraichirCommande($commande, admins: in_array($evenement->evenement, ['ouvrir_litige', 'arbitrage_liberer', 'arbitrage_rembourser'], true));
        });
    }

    // ------------------------------------------------------------------ Retraits

    public function retraitDemande(RetraitDemande $evenement): void
    {
        $this->proteger(function () use ($evenement): void {
            $retrait = $evenement->retrait;
            $prestataire = User::query()->find($retrait->user_id);
            $admins = $this->administrateurs();

            $this->notifications->envoyerA($admins, 'admin_retrait', 'Nouvelle demande de retrait', Format::fcfa($retrait->montant).' vers '.$retrait->methode.($prestataire ? ' pour '.$prestataire->nom_complet : '').'.', '/admin/retraits', 'portefeuille');
            $this->diffuseur->versPlusieurs($admins, 'retrait', ['retrait_id' => $retrait->id]);
        });
    }

    public function retraitTraite(RetraitTraite $evenement): void
    {
        $this->proteger(function () use ($evenement): void {
            /** @var Retrait $retrait */
            $retrait = $evenement->retrait;
            $prestataire = User::query()->find($retrait->user_id);

            if ($prestataire === null) {
                return;
            }

            if ($retrait->statut === Retrait::EFFECTUE) {
                $this->notifications->envoyer($prestataire, 'retrait_effectue', 'Retrait effectué', Format::fcfa($retrait->montant).' ont été envoyés vers '.$retrait->methode.' ('.$retrait->destination.').', '/prestataire/wallet', 'coche', email: true, bouton: 'Voir mon wallet');
            } else {
                $motif = $retrait->motif_refus ? ' Motif : '.$retrait->motif_refus : '';
                $this->notifications->envoyer($prestataire, 'retrait_refuse', 'Retrait refusé', 'Votre demande de '.Format::fcfa($retrait->montant).' a été refusée ; le montant est revenu sur votre wallet.'.$motif, '/prestataire/wallet', 'interdit', email: true, bouton: 'Voir mon wallet');
            }

            $this->diffuseur->vers($prestataire, 'retrait', ['retrait_id' => $retrait->id]);
            $this->diffuseur->versPlusieurs($this->administrateurs(), 'retrait', ['retrait_id' => $retrait->id]);
        });
    }

    // ------------------------------------------------------------------- Comptes

    /** Appelé après l'inscription : bienvenue au nouveau compte, et alerte des administrateurs pour un prestataire à valider. */
    public function inscription(User $utilisateur): void
    {
        $this->proteger(function () use ($utilisateur): void {
            if ($utilisateur->est_prestataire) {
                $this->notifications->envoyer($utilisateur, 'bienvenue', 'Bienvenue sur KoudMain', 'Votre compte prestataire est créé. Un administrateur vérifie votre profil : vous pourrez vous connecter et publier vos prestations dès sa validation.', '/connexion', 'utilisateur-valide', email: true, bouton: 'Se connecter');
                $this->notifications->envoyerA($this->administrateurs(), 'admin_prestataire', 'Prestataire à valider', $utilisateur->nom_complet.' vient de s\'inscrire.', '/admin/prestataires', 'utilisateur-valide');
                $this->diffuseur->versPlusieurs($this->administrateurs(), 'admin', ['sujet' => 'prestataires']);
            } else {
                $this->notifications->envoyer($utilisateur, 'bienvenue', 'Bienvenue sur KoudMain', 'Votre compte est prêt. Parcourez le catalogue, rechargez votre wallet et réservez votre premier service : votre argent reste en séquestre jusqu\'à la fin de la prestation.', '/client', 'coeur', email: true, bouton: 'Découvrir le catalogue');
            }
        });
    }

    public function prestataireValide(User $prestataire): void
    {
        $this->proteger(fn () => $this->notifications->envoyer($prestataire, 'prestataire_valide', 'Profil validé', 'Votre profil est validé : vous pouvez publier vos prestations et recevoir des commandes.', '/prestataire', 'utilisateur-valide', email: true, bouton: 'Ouvrir mon espace'));
    }

    public function prestataireSuspendu(User $prestataire): void
    {
        $this->proteger(fn () => $this->notifications->envoyer($prestataire, 'prestataire_suspendu', 'Profil suspendu', 'Votre accès à KoudMain est suspendu : vos offres ne sont plus au catalogue. Contactez l\'administration pour en savoir plus.', '/connexion', 'interdit', email: true, bouton: 'Se connecter'));
    }

    // ---------------------------------------------------------------------- Avis

    public function avisRecu(Avis $avis, string $titrePrestation, bool $modifie = false): void
    {
        $this->proteger(function () use ($avis, $titrePrestation, $modifie): void {
            $prestataire = User::query()->find($avis->prestation()->value('prestataire_id'));
            $client = User::query()->find($avis->user_id);

            if ($prestataire === null) {
                return;
            }

            $this->notifications->envoyer(
                $prestataire, 'avis_recu', $modifie ? 'Avis modifié' : 'Nouvel avis',
                ($client?->prenom ?? 'Un client').' a donné '.$avis->note.'/5 à « '.$titrePrestation.' »'.($avis->commentaire ? ' : « '.Str::limit($avis->commentaire, 120).' »' : '.'),
                '/prestataire/commandes/'.$avis->commande_id, 'etoile',
            );
        });
    }

    // ------------------------------------------------------------------- Outils

    private function demandeDeNote(Commande $commande, string $debut): void
    {
        $titre = $this->titre($commande);

        $this->notifications->envoyer(
            $commande->client, 'avis_demande', 'Comment était la prestation ?', "$debut Donnez une note à « $titre » : cela aide les autres clients à choisir.",
            "/client/commandes/{$commande->id}#avis", 'etoile', email: true, bouton: 'Donner mon avis',
        );
    }

    /** Fait actualiser, dans les navigateurs concernés, les pages qui affichent cette commande et les pastilles du menu. */
    private function rafraichirCommande(Commande $commande, bool $admins = false): void
    {
        $donnees = ['commande_id' => $commande->id, 'statut' => $commande->statut->value];
        $destinataires = [$commande->client_id, $commande->prestataire_id];

        if ($admins) {
            $destinataires = [...$destinataires, ...$this->administrateurs()->pluck('id')->all()];
        }

        $this->diffuseur->versPlusieurs(array_unique($destinataires), 'commande', $donnees);
    }

    private function charger(Commande $commande): Commande
    {
        return $commande->loadMissing(['client', 'prestataire', 'prestations']);
    }

    private function titre(Commande $commande): string
    {
        return $commande->prestations->first()?->titre ?? 'votre commande';
    }

    private function montant(Commande $commande): string
    {
        return Format::fcfa($commande->montant_total);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function administrateurs()
    {
        return User::query()->where('est_admin', true)->get();
    }

    private function proteger(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::error('notification.ecouteur_echoue', ['erreur' => $e->getMessage(), 'fichier' => $e->getFile().':'.$e->getLine()]);
        }
    }
}
