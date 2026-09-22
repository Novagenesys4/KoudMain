<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Développement : les photos vont dans public/uploads (disque "medias_local", voir config/filesystems.php). */
final class StockageLocal implements StockageMedia
{
    public function __construct(private readonly string $disque = 'medias_local')
    {
    }

    public function nom(): string
    {
        return 'local';
    }

    public function ecrire(string $chemin, string $binaire, string $mime): void
    {
        if (! Storage::disk($this->disque)->put($chemin, $binaire)) {
            throw new RuntimeException("Impossible d'écrire la photo « $chemin » (droits d'écriture sur public/uploads ?).");
        }
    }

    public function supprimer(string ...$chemins): void
    {
        try {
            Storage::disk($this->disque)->delete(array_values($chemins));
        } catch (Throwable $e) {
            Log::warning('media.suppression_locale_echec', ['chemins' => $chemins, 'erreur' => $e->getMessage()]);
        }
    }

    public function url(string $chemin): string
    {
        return Storage::disk($this->disque)->url($chemin);
    }
}
