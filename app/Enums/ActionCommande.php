<?php

namespace App\Enums;

use App\Models\Commande;
use App\Models\User;

/**
 * Ce que l'on peut FAIRE à une commande, et qui a le droit de le faire.
 * C'est la table de transitions du workflow : CommandeService n'accepte rien d'autre.
 *
 *   En attente ─ accepter (prestataire) ─▶ Acceptée ─ démarrer (prestataire) ─▶ En cours ─ terminer (prestataire) ─▶ Terminée
 *   En attente / Acceptée ─ annuler (client ou prestataire) ─▶ Annulée (le client est remboursé)
 *   En cours, ou Terminée sans confirmation ─ ouvrir un litige (client, motif obligatoire) ─▶ Litige (l'argent reste bloqué jusqu'à l'arbitrage d'un administrateur)
 *   Terminée ─ confirmer la réception (client) : le paiement est libéré au prestataire
 */
enum ActionCommande: string
{
    case Accepter = 'accepter';
    case Demarrer = 'demarrer';
    case Terminer = 'terminer';
    case Annuler = 'annuler';
    case OuvrirLitige = 'ouvrir_litige';
    case ConfirmerReception = 'confirmer_reception';

    public function libelle(): string
    {
        return match ($this) {
            self::Accepter => 'Accepter',
            self::Demarrer => 'Démarrer',
            self::Terminer => 'Marquer terminée',
            self::Annuler => 'Annuler',
            self::OuvrirLitige => 'Signaler un problème',
            self::ConfirmerReception => 'Confirmer la réception',
        };
    }

    /** @return list<StatutCommande> les statuts depuis lesquels l'action est possible */
    public function depuis(): array
    {
        return match ($this) {
            self::Accepter => [StatutCommande::EnAttente],
            self::Demarrer => [StatutCommande::Acceptee],
            self::Terminer => [StatutCommande::EnCours],
            self::Annuler => [StatutCommande::EnAttente, StatutCommande::Acceptee],
            self::OuvrirLitige => [StatutCommande::EnCours, StatutCommande::Terminee], // Terminée : tant que le client n'a pas confirmé la réception
            self::ConfirmerReception => [StatutCommande::Terminee],
        };
    }

    /** Le statut atteint (la confirmation de réception ne change pas le statut : la commande reste « Terminée »). */
    public function vers(): ?StatutCommande
    {
        return match ($this) {
            self::Accepter => StatutCommande::Acceptee,
            self::Demarrer => StatutCommande::EnCours,
            self::Terminer => StatutCommande::Terminee,
            self::Annuler => StatutCommande::Annulee,
            self::OuvrirLitige => StatutCommande::Litige,
            self::ConfirmerReception => null,
        };
    }

    /** @return list<'client'|'prestataire'> */
    public function acteurs(): array
    {
        return match ($this) {
            self::Accepter, self::Demarrer, self::Terminer => ['prestataire'],
            self::OuvrirLitige, self::ConfirmerReception => ['client'],
            self::Annuler => ['client', 'prestataire'],
        };
    }

    public function motifObligatoire(): bool
    {
        return $this === self::OuvrirLitige;
    }

    public function motifPossible(): bool
    {
        return $this === self::OuvrirLitige || $this === self::Annuler;
    }

    /** L'utilisateur est-il, sur CETTE commande, l'un des acteurs autorisés ? (un administrateur passe par l'arbitrage) */
    public function autorisePour(User $utilisateur, Commande $commande): bool
    {
        foreach ($this->acteurs() as $role) {
            $id = $role === 'client' ? $commande->client_id : $commande->prestataire_id;

            if ($id === $utilisateur->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les actions proposées à cet utilisateur sur cette commande, dans l'état où elle est.
     *
     * @return list<self>
     */
    public static function possibles(Commande $commande, User $utilisateur): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $action) => $action->autorisePour($utilisateur, $commande)
                && in_array($commande->statut, $action->depuis(), true)
                && (! in_array($action, [self::ConfirmerReception, self::OuvrirLitige], true) || $commande->statut !== StatutCommande::Terminee || $commande->validee_client_at === null),
        ));
    }
}
