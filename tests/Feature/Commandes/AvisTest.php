<?php

namespace Tests\Feature\Commandes;

use App\Enums\ActionCommande;
use App\Enums\StatutCommande;
use App\Exceptions\OperationRefusee;
use App\Models\Avis;
use App\Models\Commande;
use App\Models\Prestation;
use App\Models\User;
use App\Services\AvisService;
use App\Services\CommandeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les avis : qui peut noter, quand, et ce qui se passe ensuite (modification archivée, prestataire prévenu). */
class AvisTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private User $client;

    private User $prestataire;

    private Prestation $offre;

    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        config(['koudmain.paiement.driver' => 'simulation', 'koudmain.temps_reel.actif' => true]);
        Mail::fake();

        $this->prestataire = $this->unPrestataire();
        $this->client = $this->unClient(80000);
        $this->offre = $this->uneOffre($this->prestataire, 10000);
        $this->commande = $this->commander($this->client, $this->offre);
    }

    private function terminer(): void
    {
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $a) {
            app(CommandeService::class)->agir($this->commande->fresh(), $this->prestataire, $a);
        }
        $this->commande->refresh();
    }

    private function noter(int $note = 5, ?string $commentaire = 'Très bien'): Avis
    {
        return app(AvisService::class)->donner($this->commande->fresh(), $this->client, $this->offre->id, $note, $commentaire);
    }

    public function test_on_ne_note_qu_une_commande_terminee(): void
    {
        foreach ([StatutCommande::EnAttente, StatutCommande::Acceptee, StatutCommande::EnCours, StatutCommande::Annulee, StatutCommande::Litige] as $statut) {
            $this->commande->forceFill(['statut' => $statut])->save();

            try {
                $this->noter();
                $this->fail("Noter une commande « {$statut->value} » aurait dû être refusé.");
            } catch (OperationRefusee) {
                $this->assertSame(0, Avis::query()->count());
            }
        }
    }

    public function test_le_client_note_une_commande_terminee_et_le_prestataire_est_prevenu(): void
    {
        $this->terminer();
        DB::table('evenements_temps_reel')->delete();

        $avis = $this->noter(4, '  Ponctuel et soigné.  ');

        $this->assertSame(4, $avis->note);
        $this->assertSame('Ponctuel et soigné.', $avis->commentaire);
        $this->assertNull($avis->modifie_at);
        $notification = $this->prestataire->notifications()->where('type', 'avis_recu')->firstOrFail();
        $this->assertSame('Nouvel avis', $notification->data['titre']);
        $this->assertStringContainsString('4/5', $notification->data['texte']);
        $this->assertSame(1, DB::table('evenements_temps_reel')->where('user_id', $this->prestataire->id)->where('type', 'notification')->count());
    }

    public function test_modifier_son_avis_archive_l_ancienne_version(): void
    {
        $this->terminer();
        $this->noter(2, 'Décevant');

        $this->noter(4, 'Finalement très bien');

        $this->assertSame(1, Avis::query()->count());
        $avis = Avis::query()->firstOrFail();
        $this->assertSame(4, $avis->note);
        $this->assertNotNull($avis->modifie_at);
        $archive = DB::table('avis_historiques')->first();
        $this->assertSame(2, $archive->note);
        $this->assertSame('Décevant', $archive->commentaire);
        $this->assertSame(['Nouvel avis', 'Avis modifié'], $this->prestataire->notifications()->where('type', 'avis_recu')->oldest()->get()->map(fn ($n) => $n->data['titre'])->all());
    }

    public function test_renvoyer_le_meme_avis_ne_previent_pas_pour_rien(): void
    {
        $this->terminer();
        $this->noter(5, 'Parfait');
        $this->noter(5, 'Parfait');

        $this->assertSame(1, $this->prestataire->notifications()->where('type', 'avis_recu')->count());
        $this->assertSame(0, DB::table('avis_historiques')->count());
    }

    public function test_les_regles_de_la_note_et_du_commentaire(): void
    {
        $this->terminer();

        foreach ([0, 6, -1] as $note) {
            try {
                $this->noter($note);
                $this->fail("La note $note aurait dû être refusée.");
            } catch (OperationRefusee) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(OperationRefusee::class);
        $this->noter(5, str_repeat('a', 1001));
    }

    public function test_seul_le_client_de_la_commande_peut_noter_et_seulement_ses_prestations(): void
    {
        $this->terminer();

        try {
            app(AvisService::class)->donner($this->commande->fresh(), $this->unClient(), $this->offre->id, 5, null);
            $this->fail('Un autre client ne peut pas noter.');
        } catch (OperationRefusee) {
            $this->assertFalse(app(AvisService::class)->peutNoter($this->commande, $this->prestataire));
        }

        $this->expectException(OperationRefusee::class);
        app(AvisService::class)->donner($this->commande->fresh(), $this->client, $this->uneOffre()->id, 5, null); // une prestation qui n'est pas dans la commande
    }

    public function test_un_commentaire_vide_est_enregistre_sans_texte(): void
    {
        $this->terminer();

        $this->assertNull($this->noter(3, "  \n ")->commentaire);
    }

    // ------------------------------------------------------------------ Pages

    public function test_le_client_voit_le_formulaire_seulement_apres_la_fin_de_la_prestation(): void
    {
        $this->actingAs($this->client)->get(route('client.commandes.voir', $this->commande))->assertOk()->assertDontSee('Publier mon avis');

        $this->terminer();

        $this->actingAs($this->client)->get(route('client.commandes.voir', $this->commande))->assertOk()
            ->assertSee('Publier mon avis')->assertSee('name="note"', false)->assertSee('id="avis"', false);
        $this->actingAs($this->prestataire)->get(route('prestataire.commandes.voir', $this->commande))->assertOk()->assertDontSee('Publier mon avis');
    }

    public function test_le_formulaire_enregistre_l_avis_puis_le_montre_au_prestataire(): void
    {
        $this->terminer();

        $this->actingAs($this->client)->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id, 'note' => 5, 'commentaire' => 'Excellent travail'])
            ->assertRedirect(route('client.commandes.voir', $this->commande).'#avis')->assertSessionHas('succes');

        $this->actingAs($this->client)->get(route('client.commandes.voir', $this->commande))->assertSee('Modifier mon avis')->assertSee('Excellent travail');
        $this->actingAs($this->prestataire)->get(route('prestataire.commandes.voir', $this->commande))->assertSee('Avis du client')->assertSee('Excellent travail');
        $this->get(route('prestations.voir', $this->offre))->assertOk()->assertSee('Excellent travail');
    }

    public function test_le_formulaire_refuse_une_note_invalide_ou_une_commande_d_un_autre(): void
    {
        $this->terminer();

        $this->actingAs($this->client)->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id, 'note' => 9])->assertSessionHasErrors('note');
        $this->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id])->assertSessionHasErrors('note');
        $this->assertSame(0, Avis::query()->count());

        $this->actingAs($this->unClient())->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id, 'note' => 5])->assertNotFound();
        $this->actingAs($this->prestataire)->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id, 'note' => 5])->assertForbidden();
    }

    public function test_noter_avant_la_fin_est_refuse_avec_un_message(): void
    {
        $this->actingAs($this->client)->post(route('client.commandes.avis', $this->commande), ['prestation_id' => $this->offre->id, 'note' => 5])
            ->assertSessionHas('erreur');
        $this->assertSame(0, Avis::query()->count());
    }

    public function test_la_note_moyenne_du_catalogue_suit_les_avis(): void
    {
        $this->terminer();
        $this->noter(4);

        // Les cartes reçoivent la moyenne et le nombre d'avis (l'affichage « 4,0 » est fait par la carte React).
        $this->get(route('catalogue'))->assertOk()->assertSee('&quot;note&quot;:4', false)->assertSee('&quot;avis&quot;:1', false);
    }
}
