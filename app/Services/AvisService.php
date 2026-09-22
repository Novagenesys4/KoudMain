<?php

namespace App\Services;

use App\Enums\StatutCommande;
use App\Exceptions\OperationRefusee;
use App\Models\Avis;
use App\Models\Commande;
use App\Models\User;
use App\Services\Notifications\Ecouteur;
use Illuminate\Support\Facades\DB;

/**
 * Les avis : un client note (1 à 5) et commente une prestation d'une de SES commandes, une fois cette commande terminée.
 *
 * Règles (ici et nulle part ailleurs) :
 *  - seul le client de la commande peut noter, et seulement quand elle est « Terminée » ;
 *  - un avis par prestation et par commande ; il reste modifiable, et chaque version est archivée (avis_historiques) ;
 *  - le prestataire est prévenu (notification + temps réel) à chaque avis.
 */
class AvisService
{
    public const COMMENTAIRE_MAX = 1000;

    public function __construct(private readonly Ecouteur $ecouteur)
    {
    }

    public function peutNoter(Commande $commande, User $utilisateur): bool
    {
        return $commande->client_id === $utilisateur->id && $commande->statut === StatutCommande::Terminee;
    }

    /**
     * @throws OperationRefusee
     */
    public function donner(Commande $commande, User $client, int $prestationId, int $note, ?string $commentaire): Avis
    {
        if ($commande->client_id !== $client->id) {
            throw new OperationRefusee('Vous ne pouvez noter que vos propres commandes.');
        }

        if ($commande->statut !== StatutCommande::Terminee) {
            throw new OperationRefusee('Vous pourrez donner votre avis quand la prestation sera terminée.');
        }

        if ($note < 1 || $note > 5) {
            throw new OperationRefusee('Choisissez une note de 1 à 5 étoiles.');
        }

        $prestation = $commande->prestations()->whereKey($prestationId)->first();

        if ($prestation === null) {
            throw new OperationRefusee('Cette prestation ne fait pas partie de la commande.');
        }

        $commentaire = $commentaire !== null ? trim(preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $commentaire)) ?? '') : null;
        $commentaire = $commentaire === '' ? null : $commentaire;

        if ($commentaire !== null && mb_strlen($commentaire) > self::COMMENTAIRE_MAX) {
            throw new OperationRefusee('Votre commentaire ne peut pas dépasser '.self::COMMENTAIRE_MAX.' caractères.');
        }

        /** @var array{0: Avis, 1: bool} $resultat */
        $resultat = DB::transaction(function () use ($commande, $client, $prestation, $note, $commentaire): array {
            $existant = Avis::query()->where('commande_id', $commande->id)->where('prestation_id', $prestation->id)->lockForUpdate()->first();

            if ($existant === null) {
                $avis = new Avis();
                $avis->forceFill(['commande_id' => $commande->id, 'prestation_id' => $prestation->id, 'user_id' => $client->id, 'note' => $note, 'commentaire' => $commentaire])->save();

                return [$avis, false];
            }

            // Rien n'a changé : on ne prévient pas le prestataire pour rien.
            if ($existant->note === $note && $existant->commentaire === $commentaire) {
                return [$existant, false];
            }

            DB::table('avis_historiques')->insert([
                'commande_id' => $existant->commande_id, 'prestation_id' => $existant->prestation_id, 'user_id' => $existant->user_id,
                'note' => $existant->note, 'commentaire' => $existant->commentaire, 'created_at' => now(),
            ]);
            $existant->forceFill(['note' => $note, 'commentaire' => $commentaire, 'modifie_at' => now()])->save();

            return [$existant, true];
        });

        [$avis, $modifie] = $resultat;

        if ($avis->wasRecentlyCreated || $modifie) {
            $this->ecouteur->avisRecu($avis, $prestation->titre, $modifie);
        }

        return $avis;
    }
}
