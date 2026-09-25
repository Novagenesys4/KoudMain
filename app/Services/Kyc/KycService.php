<?php

namespace App\Services\Kyc;

use App\Exceptions\RefusApi;
use App\Models\DemandeKyc;
use App\Models\User;
use App\Services\Media\ImageInvalide;
use App\Services\Media\ImageProcessor;
use App\Services\Media\StockageLocal;
use App\Services\Media\StockageMedia;
use App\Services\Media\StockageSupabase;
use App\Support\Journal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vérification d'identité des prestataires (KYC).
 *
 * Chaque photo est :
 *  1. nettoyée par ImageProcessor (vrai type lu dans le fichier, image redessinée : EXIF/GPS et contenu caché supprimés) ;
 *  2. CHIFFRÉE avec la clé de l'application (AES-256 + MAC, Crypt) : même un accès direct au stockage ne montre rien ;
 *  3. rangée hors de toute adresse publique (disque privé en local, bucket privé Supabase en production).
 * La base garde l'emplacement et l'empreinte SHA-256 de l'image nettoyée.
 */
class KycService
{
    public function __construct(private readonly ?StockageMedia $stockage = null, private readonly ?ImageProcessor $images = null) {}

    /** L'état de la dernière demande, pour l'application. @return array<string, mixed> */
    public function etat(User $prestataire): array
    {
        $demande = $prestataire->demandeKyc()->with(['categories:id,nom', 'villes:id,nom', 'documents:id,demande_kyc_id,type'])->first();
        $prestataire->setRelation('demandeKyc', $demande);

        return [
            'statut' => $prestataire->statutKyc(),
            'motif_refus' => $demande?->statut === DemandeKyc::REFUSEE ? $demande->motif_refus : null,
            'soumise_le' => $demande?->created_at?->toIso8601String(),
            'categories' => $demande?->categories->pluck('id')->values()->all() ?? [],
            'villes' => $demande?->villes->pluck('id')->values()->all() ?? [],
            'documents' => $demande?->documents->pluck('type')->values()->all() ?? [],
        ];
    }

    /**
     * Enregistre une demande complète (services, zones, 3 photos).
     *
     * @param  list<int>  $categories
     * @param  list<int>  $villes
     * @param  array<string, UploadedFile>  $photos  recto, verso, selfie
     *
     * @throws RefusApi|ValidationException
     */
    public function soumettre(User $prestataire, array $categories, array $villes, array $photos): DemandeKyc
    {
        $statut = $prestataire->statutKyc();

        if ($statut === DemandeKyc::VALIDEE) {
            throw new RefusApi('Votre profil est déjà vérifié.', 422, 'operation_refusee');
        }

        if ($statut === DemandeKyc::EN_ATTENTE) {
            throw new RefusApi('Vos documents sont déjà en cours de vérification par l\'équipe KoudMain.', 422, 'operation_refusee');
        }

        // 1. Nettoyer toutes les photos AVANT d'écrire quoi que ce soit : une photo refusée n'en laisse aucune derrière elle.
        $propres = [];
        foreach (DemandeKyc::DOCUMENTS as $type) {
            try {
                $propres[$type] = $this->processeur()->traiter($photos[$type]->getRealPath());
            } catch (ImageInvalide $e) {
                throw ValidationException::withMessages([$type => $e->getMessage()]);
            }
        }

        // 2. Chiffrer et ranger.
        $stockage = $this->stockage();
        $ecrits = [];
        $documents = [];

        try {
            foreach ($propres as $type => $image) {
                $chemin = 'kyc/'.$prestataire->id.'/'.Str::uuid()->toString().'.bin';
                $stockage->ecrire($chemin, Crypt::encryptString($image->binaire), 'application/octet-stream');
                $ecrits[] = $chemin;
                $documents[] = [
                    'type' => $type,
                    'disk' => $stockage->nom(),
                    'chemin' => $chemin,
                    'mime' => $image->mime,
                    'taille_octets' => strlen($image->binaire),
                    'empreinte' => hash('sha256', $image->binaire),
                ];
            }

            // 3. Enregistrer la demande ; les fichiers d'une ancienne demande refusée sont effacés (on ne garde que l'utile).
            $anciens = [];
            $demande = DB::transaction(function () use ($prestataire, $categories, $villes, $documents, &$anciens): DemandeKyc {
                User::query()->whereKey($prestataire->id)->lockForUpdate()->first();

                if (DemandeKyc::query()->where('user_id', $prestataire->id)->where('statut', DemandeKyc::EN_ATTENTE)->exists()) {
                    throw new RefusApi('Vos documents sont déjà en cours de vérification par l\'équipe KoudMain.', 422, 'operation_refusee');
                }

                $precedentes = DemandeKyc::query()->where('user_id', $prestataire->id)->with('documents')->get();
                foreach ($precedentes as $ancienne) {
                    foreach ($ancienne->documents as $doc) {
                        $anciens[] = $doc->chemin;
                    }
                    $ancienne->documents()->delete();
                }

                $demande = new DemandeKyc(['statut' => DemandeKyc::EN_ATTENTE]);
                $demande->user()->associate($prestataire);
                $demande->save();
                $demande->categories()->sync($categories);
                $demande->villes()->sync($villes);
                $demande->documents()->createMany($documents);

                return $demande;
            });
        } catch (Throwable $e) {
            $stockage->supprimer(...$ecrits);

            throw $e;
        }

        if ($anciens !== []) {
            $stockage->supprimer(...$anciens);
        }

        $prestataire->unsetRelation('demandeKyc');
        Journal::info('api.kyc.soumise', ['utilisateur' => $prestataire->id, 'demande' => $demande->id]);

        return $demande;
    }

    /** Pour l'équipe de validation : l'image déchiffrée d'un document (binaire WebP ou JPEG). */
    public function lire(string $chiffre): string
    {
        return Crypt::decryptString($chiffre);
    }

    private function stockage(): StockageMedia
    {
        if ($this->stockage !== null) {
            return $this->stockage;
        }

        $media = config('koudmain.media');

        return match (config('koudmain.api.kyc.stockage')) {
            'supabase' => new StockageSupabase($media['supabase']['url'], $media['supabase']['cle_service'], config('koudmain.api.kyc.bucket')),
            default => new StockageLocal('local'), // storage/app/private : jamais servi par le serveur web
        };
    }

    private function processeur(): ImageProcessor
    {
        if ($this->images !== null) {
            return $this->images;
        }

        $media = config('koudmain.media');

        return new ImageProcessor(
            largeurMax: 2000, // une pièce d'identité doit rester lisible
            qualite: 85,
            pixelsMax: $media['pixels_max'],
            largeurMin: $media['dimensions_min'][0],
            hauteurMin: $media['dimensions_min'][1],
        );
    }
}
