<?php

namespace App\Services\Media;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Production : Supabase Storage, par son API HTTP (aucune bibliothèque supplémentaire à installer).
 *
 * Le « bucket » (« medias » par défaut) doit être créé dans Supabase, en mode PUBLIC : les photos de
 * prestations sont faites pour être vues par tout le monde. L'envoi, lui, n'est possible qu'avec la clé
 * « service_role », qui ne quitte jamais le serveur.
 */
final class StockageSupabase implements StockageMedia
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $cleService,
        private readonly string $bucket = 'medias',
    ) {
    }

    public function nom(): string
    {
        return 'supabase';
    }

    public function ecrire(string $chemin, string $binaire, string $mime): void
    {
        $reponse = $this->client()
            ->withHeaders([
                'x-upsert' => 'true',
                // Le nom du fichier change à chaque nouvelle photo : on peut donc la garder en cache un an.
                'cache-control' => 'max-age=31536000',
            ])
            ->withBody($binaire, $mime)
            ->post($this->adresseObjet($chemin));

        if ($reponse->failed()) {
            throw new RuntimeException(sprintf(
                "Supabase Storage a refusé l'envoi (HTTP %d) : %s",
                $reponse->status(),
                Str::limit($reponse->body(), 200),
            ));
        }
    }

    public function supprimer(string ...$chemins): void
    {
        if ($chemins === []) {
            return;
        }

        try {
            $reponse = $this->client()->delete($this->url.'/storage/v1/object/'.$this->bucket, ['prefixes' => array_values($chemins)]);

            if ($reponse->failed()) {
                Log::warning('media.suppression_supabase_echec', ['chemins' => $chemins, 'statut' => $reponse->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('media.suppression_supabase_echec', ['chemins' => $chemins, 'erreur' => $e->getMessage()]);
        }
    }

    public function url(string $chemin): string
    {
        return $this->url.'/storage/v1/object/public/'.$this->bucket.'/'.$this->segments($chemin);
    }

    private function client(): PendingRequest
    {
        if ($this->url === '' || $this->cleService === null || $this->cleService === '') {
            throw new RuntimeException('Supabase Storage n\'est pas configuré : renseignez SUPABASE_URL et SUPABASE_SERVICE_KEY.');
        }

        return Http::withToken($this->cleService)
            ->withHeaders(['apikey' => $this->cleService])
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    private function adresseObjet(string $chemin): string
    {
        return $this->url.'/storage/v1/object/'.$this->bucket.'/'.$this->segments($chemin);
    }

    private function segments(string $chemin): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $chemin)));
    }
}
