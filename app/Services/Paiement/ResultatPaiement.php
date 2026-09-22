<?php

namespace App\Services\Paiement;

use App\Models\Paiement;

/** Ce que l'agrégateur répond : le statut du paiement, et si besoin la page où envoyer le client. */
final class ResultatPaiement
{
    /** @param  'en_attente'|'reussi'|'echoue'  $statut */
    public function __construct(
        public readonly string $statut,
        public readonly ?string $url = null,
        public readonly ?string $referenceFournisseur = null,
        public readonly ?float $montant = null,
        public readonly ?string $motif = null,
    ) {
    }

    public static function reussi(?float $montant = null, ?string $referenceFournisseur = null): self
    {
        return new self(Paiement::REUSSI, null, $referenceFournisseur, $montant);
    }

    public static function echoue(string $motif): self
    {
        return new self(Paiement::ECHOUE, motif: $motif);
    }

    public static function enAttente(?string $url = null, ?string $referenceFournisseur = null): self
    {
        return new self(Paiement::EN_ATTENTE, $url, $referenceFournisseur);
    }
}
