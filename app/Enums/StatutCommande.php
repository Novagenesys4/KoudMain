<?php

namespace App\Enums;

/**
 * Les 6 statuts du workflow de commande.
 * La valeur (string) est ce qui est stocké en base ; libelle() sert à l'affichage.
 */
enum StatutCommande: string
{
    case EnAttente = 'en_attente';
    case Acceptee = 'acceptee';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';
    case Annulee = 'annulee';
    case Litige = 'litige';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Acceptee => 'Acceptée',
            self::EnCours => 'En cours',
            self::Terminee => 'Terminée',
            self::Annulee => 'Annulée',
            self::Litige => 'Litige',
        };
    }

    /** Nom de la couleur du badge : orange, bleu, violet, vert, rouge, gris (voir .statut-* dans app.css). */
    public function nuance(): string
    {
        return match ($this) {
            self::EnAttente => 'attente',
            self::Acceptee => 'acceptee',
            self::EnCours => 'encours',
            self::Terminee => 'terminee',
            self::Annulee => 'annulee',
            self::Litige => 'litige',
        };
    }
}
