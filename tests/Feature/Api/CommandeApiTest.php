<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Escrow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Le parcours complet du prototype : réserver (séquestre) → accepter → démarrer → terminer → valider (paiement libéré) → noter. */
class CommandeApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        $this->figerLeTemps();
    }

    private function verifie(User $u): User
    {
        $u->forceFill(['telephone_verifie_at' => now()])->save();

        return $u;
    }

    /** @return array<string, mixed> */
    private function reservation(int $prestationId, array $surcharge = []): array
    {
        return $surcharge + ['prestation_id' => $prestationId, 'quantite' => 1, 'date' => '2026-09-22', 'heure' => '10:00', 'precisions' => 'Portail bleu, près de la pharmacie', 'mode_paiement' => 'mobile_money'];
    }

    public function test_parcours_complet_avec_sequestre(): void
    {
        $client = $this->verifie($this->unClient(20000));
        $offre = $this->uneOffre(prix: 15000);
        $pro = $this->verifie($offre->prestataire);

        Sanctum::actingAs($client);
        $id = $this->postJson('/api/v1/commandes', $this->reservation($offre->id))
            ->assertCreated()
            ->assertJsonPath('data.statut', 'en_attente')
            ->assertJsonPath('data.etape_suivi', 0)
            ->assertJsonPath('data.sequestre.statut', 'bloque')
            ->assertJsonPath('data.sequestre.montant', 15000)
            ->assertJsonPath('data.mon_role', 'client')
            ->assertJsonPath('data.actions', ['annuler'])
            ->json('data.id');

        $this->assertSame(5000.0, $this->solde($client));

        Sanctum::actingAs($pro);
        $this->getJson('/api/v1/commandes?onglet=nouvelles')->assertOk()->assertJsonPath('meta.role', 'prestataire')->assertJsonPath('data.0.id', $id);
        foreach (['accepter' => 1, 'demarrer' => 2, 'terminer' => 3] as $action => $etape) {
            $this->postJson("/api/v1/commandes/$id/actions/$action")->assertOk()->assertJsonPath('data.etape_suivi', $etape);
        }

        Sanctum::actingAs($client);
        $this->getJson("/api/v1/commandes/$id")->assertJsonPath('data.actions', ['ouvrir_litige', 'confirmer_reception']);
        $this->postJson("/api/v1/commandes/$id/actions/confirmer_reception")
            ->assertOk()->assertJsonPath('data.etape_suivi', 4)->assertJsonPath('data.sequestre.statut', 'libere');
        $this->assertSame(15000.0, $this->solde($pro));

        $this->postJson("/api/v1/commandes/$id/avis", ['note' => 5, 'commentaire' => 'Ponctuelle et soigneuse.'])->assertCreated();
        $this->getJson('/api/v1/commandes?onglet=terminees')->assertJsonPath('data.0.avis.0.note', 5);
    }

    public function test_solde_insuffisant_indique_le_montant_manquant(): void
    {
        Sanctum::actingAs($this->verifie($this->unClient(4000)));
        $offre = $this->uneOffre(prix: 15000);

        $this->postJson('/api/v1/commandes', $this->reservation($offre->id))
            ->assertStatus(422)->assertJsonPath('code', 'solde_insuffisant')->assertJsonPath('data.manquant', 11000);
        $this->assertSame(0, Commande::query()->count());
    }

    public function test_paiement_physique_sans_sequestre(): void
    {
        Sanctum::actingAs($this->verifie($this->unClient()));
        $offre = $this->uneOffre(prix: 15000);

        $this->postJson('/api/v1/commandes', $this->reservation($offre->id, ['mode_paiement' => 'physique']))
            ->assertCreated()->assertJsonPath('data.sequestre', null)->assertJsonPath('data.mode_paiement', 'physique');
    }

    public function test_numero_non_verifie_et_role_exiges(): void
    {
        $offre = $this->uneOffre();

        Sanctum::actingAs($this->unClient(20000));
        $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->assertStatus(403)->assertJsonPath('code', 'telephone_non_verifie');

        Sanctum::actingAs($this->verifie($offre->prestataire));
        $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->assertStatus(403)->assertJsonPath('code', 'role_requis');
    }

    public function test_creneau_invalide_et_litige_sans_motif(): void
    {
        $client = $this->verifie($this->unClient(20000));
        $offre = $this->uneOffre(prix: 5000);
        Sanctum::actingAs($client);

        $this->postJson('/api/v1/commandes', $this->reservation($offre->id, ['heure' => '10:10']))
            ->assertStatus(422)->assertJsonPath('code', 'operation_refusee');

        $id = $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->json('data.id');
        $this->postJson("/api/v1/commandes/$id/actions/ouvrir_litige", ['motif' => 'court'])->assertStatus(422);
        $this->postJson("/api/v1/commandes/$id/actions/accepter")->assertStatus(422)->assertJsonPath('message', 'Vous ne pouvez pas effectuer cette action sur cette commande.');
        $this->postJson("/api/v1/commandes/$id/actions/inconnue")->assertStatus(404);
    }

    public function test_annulation_rembourse(): void
    {
        $client = $this->verifie($this->unClient(20000));
        $offre = $this->uneOffre(prix: 5000);
        Sanctum::actingAs($client);

        $id = $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->json('data.id');
        $this->postJson("/api/v1/commandes/$id/actions/annuler", ['motif' => 'Empêchement'])->assertOk()->assertJsonPath('data.statut', 'annulee');
        $this->assertSame(20000.0, $this->solde($client));
        $this->assertSame(Escrow::REMBOURSE, Escrow::query()->where('commande_id', $id)->value('statut'));
    }

    public function test_la_commande_d_un_autre_est_introuvable(): void
    {
        $client = $this->verifie($this->unClient(20000));
        $offre = $this->uneOffre(prix: 5000);
        Sanctum::actingAs($client);
        $id = $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->json('data.id');

        Sanctum::actingAs($this->verifie($this->unClient()));
        $this->getJson("/api/v1/commandes/$id")->assertStatus(404);
        $this->postJson("/api/v1/commandes/$id/actions/annuler")->assertStatus(404);
        $this->getJson("/api/v1/commandes/$id/messages")->assertStatus(404);
        $this->postJson("/api/v1/commandes/$id/messages", ['contenu' => 'Salut'])->assertStatus(404);
        $this->getJson('/api/v1/commandes')->assertJsonPath('meta.total', 0);
    }

    public function test_messagerie_et_notifications(): void
    {
        $client = $this->verifie($this->unClient(20000));
        $offre = $this->uneOffre(prix: 5000);
        $pro = $offre->prestataire;
        Sanctum::actingAs($client);
        $id = $this->postJson('/api/v1/commandes', $this->reservation($offre->id))->json('data.id');

        $this->postJson("/api/v1/commandes/$id/messages", ['contenu' => 'Bonjour, portail bleu.'])->assertCreated()->assertJsonPath('data.de_moi', true);

        Sanctum::actingAs($pro);
        $this->getJson('/api/v1/notifications/compteurs')->assertJsonPath('data.messages_non_lus', 1);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.commande_id', $id)->assertJsonMissingPath('data.0.url');
        $this->getJson("/api/v1/commandes/$id/messages")->assertOk()->assertJsonPath('data.0.contenu', 'Bonjour, portail bleu.')->assertJsonPath('data.0.de_moi', false);
        $this->getJson('/api/v1/notifications/compteurs')->assertJsonPath('data.messages_non_lus', 0);

        // La commande passée a créé une notification pour le prestataire.
        $notif = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertSame(['type' => 'commande', 'id' => $id], $notif['cible']);
        $this->postJson('/api/v1/notifications/'.$notif['id'].'/read')->assertOk();
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.non_lues', 0);

        // La notification d'un autre : 404.
        Sanctum::actingAs($client);
        $this->postJson('/api/v1/notifications/'.$notif['id'].'/read')->assertStatus(404);
    }
}
