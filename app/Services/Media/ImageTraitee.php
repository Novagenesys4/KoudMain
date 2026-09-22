<?php

namespace App\Services\Media;

/**
 * Le résultat du traitement d'une image : l'image nettoyée, prête à être stockée.
 */
final class ImageTraitee
{
    public function __construct(
        public readonly string $binaire,
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $largeur,
        public readonly int $hauteur,
    ) {
    }

    public function taille(): int
    {
        return strlen($this->binaire);
    }
}
