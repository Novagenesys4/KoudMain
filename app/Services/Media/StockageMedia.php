<?php

namespace App\Services\Media;

/**
 * Un endroit où ranger les photos. Deux réalisations : le dossier public/uploads (développement)
 * et Supabase Storage (production). Le reste de l'application ne sait pas laquelle est utilisée.
 */
interface StockageMedia
{
    /** Nom enregistré avec chaque photo (colonne medias.disk) : "local" ou "supabase". */
    public function nom(): string;

    /** @throws \RuntimeException si l'écriture échoue */
    public function ecrire(string $chemin, string $binaire, string $mime): void;

    /** Supprime des fichiers. Ne lève jamais d'exception : au pire, un fichier orphelin reste (et est journalisé). */
    public function supprimer(string ...$chemins): void;

    /** Adresse publique de la photo, utilisable dans une balise <img>. */
    public function url(string $chemin): string;
}
