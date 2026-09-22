<?php

namespace App\Enums;

/**
 * Comment le client règle une commande.
 *  - Physique     : en main propre au prestataire. Aucun argent ne passe par KoudMain, donc pas de séquestre ;
 *  - MobileMoney  : prélevé sur le wallet (alimenté par Mobile Money), bloqué en séquestre ;
 *  - Carte        : prélevé sur le wallet via une carte enregistrée (non gelée), bloqué en séquestre.
 */
enum ModePaiement: string
{
    case Physique = 'physique';
    case MobileMoney = 'mobile_money';
    case Carte = 'carte';

    public function libelle(): string
    {
        return match ($this) {
            self::Physique => 'Paiement physique',
            self::MobileMoney => 'Mobile Money',
            self::Carte => 'Carte bancaire',
        };
    }

    /** Court : pour un badge ou un tableau. */
    public function court(): string
    {
        return match ($this) {
            self::Physique => 'Espèces',
            self::MobileMoney => 'Mobile Money',
            self::Carte => 'Carte',
        };
    }

    /** Nom d'icône (App\Support\Icones) pour les listes et le détail d'une commande. */
    public function icone(): string
    {
        return match ($this) {
            self::Physique => 'portefeuille',
            self::MobileMoney => 'telephone',
            self::Carte => 'carte-bancaire',
        };
    }

    /** Vrai si le montant est prélevé sur le wallet et bloqué en séquestre. */
    public function passeParLeWallet(): bool
    {
        return $this !== self::Physique;
    }
}
