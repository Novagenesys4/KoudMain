<?php

namespace App\Http\Resources\Api;

use App\Enums\ActionCommande;
use App\Enums\StatutCommande;
use App\Models\Commande;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une commande vue par l'une de ses deux parties. « actions » liste ce que la personne connectée peut faire MAINTENANT
 * (même règle que le site : ActionCommande::possibles) ; l'application n'affiche que ces boutons.
 *
 * « etape_suivi » suit la timeline du prototype : 0 envoyée · 1 acceptée · 2 démarrée · 3 terminée (à valider) · 4 réception
 * confirmée (payée). null pour une commande annulée ou en litige.
 */
class CommandeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Commande $c */
        $c = $this->resource;
        $moi = $request->user();
        $escrow = $c->relationLoaded('escrow') ? $c->escrow : null;
        $lignes = $c->relationLoaded('prestations') ? $c->prestations : collect();

        return [
            'id' => $c->id,
            'reference' => 'KM-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT),
            'statut' => $c->statut->value,
            'statut_libelle' => $c->statut->libelle(),
            'etape_suivi' => $this->etape($c),
            'reception_confirmee' => $c->validee_client_at !== null,
            'mode_paiement' => $c->mode_paiement?->value,
            'mode_paiement_libelle' => $c->mode_paiement?->libelle(),
            'montant_total' => (int) round((float) $c->montant_total),
            'date_souhaitee' => $c->date_souhaitee?->toIso8601String(),
            'duree_minutes' => $c->duree_minutes,
            'precisions' => $c->precisions,
            'titre' => $lignes->first()?->titre ?? 'Commande n° '.$c->id,
            'prestations' => $lignes->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'titre' => $p->titre,
                'prix_unitaire' => (int) round((float) $p->pivot->prix_unitaire),
                'quantite' => (int) $p->pivot->quantite,
            ])->values()->all(),
            'mon_role' => $moi?->id === $c->client_id ? 'client' : 'prestataire',
            'client' => $c->relationLoaded('client') ? [
                'id' => $c->client->id,
                'prenom' => $c->client->prenom,
                'nom_complet' => $c->client->nom_complet,
                'avatar_url' => $c->client->relationLoaded('avatar') ? $c->client->avatar?->url() : null,
            ] : null,
            'prestataire' => $c->relationLoaded('prestataire') ? new PrestataireResumeResource($c->prestataire) : null,
            'quartier' => $c->relationLoaded('quartier') && $c->quartier ? [
                'id' => $c->quartier->id,
                'nom' => $c->quartier->nom,
                'ville' => $c->quartier->relationLoaded('ville') ? $c->quartier->ville?->nom : null,
            ] : null,
            'sequestre' => $escrow ? [
                'statut' => $escrow->statut, // bloque | libere | rembourse | litige
                'montant' => (int) round((float) $escrow->montant),
                'bloque_le' => $escrow->bloque_at?->toIso8601String(),
                'libere_le' => $escrow->libere_at?->toIso8601String(),
                'rembourse_le' => $escrow->rembourse_at?->toIso8601String(),
            ] : null,
            'dates' => [
                'creee_le' => $c->created_at?->toIso8601String(),
                'acceptee_le' => $c->acceptee_at?->toIso8601String(),
                'demarree_le' => $c->debut_at?->toIso8601String(),
                'terminee_le' => $c->terminee_at?->toIso8601String(),
                'annulee_le' => $c->annulee_at?->toIso8601String(),
                'reception_confirmee_le' => $c->validee_client_at?->toIso8601String(),
            ],
            'motif_annulation' => $c->motif_annulation,
            'motif_litige' => $c->motif_litige,
            'actions' => $moi !== null ? array_map(fn (ActionCommande $a) => $a->value, ActionCommande::possibles($c, $moi)) : [],
            'avis' => $c->relationLoaded('avis') ? $c->avis->map(fn ($a) => [
                'prestation_id' => $a->prestation_id,
                'note' => $a->note,
                'commentaire' => $a->commentaire,
                'date' => $a->created_at?->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    private function etape(Commande $c): ?int
    {
        return match ($c->statut) {
            StatutCommande::EnAttente => 0,
            StatutCommande::Acceptee => 1,
            StatutCommande::EnCours => 2,
            StatutCommande::Terminee => $c->validee_client_at === null ? 3 : 4,
            default => null,
        };
    }
}
