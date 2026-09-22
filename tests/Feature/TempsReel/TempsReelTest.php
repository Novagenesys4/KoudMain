<?php

namespace Tests\Feature\TempsReel;

use App\Models\User;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Le socle du temps réel : la boîte d'événements de chaque utilisateur, le flux SSE et son rattrapage. */
class TempsReelTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        // Le flux ne reste pas ouvert 25 s en test : une seule boucle, puis il se ferme.
        config(['koudmain.temps_reel.actif' => true, 'koudmain.temps_reel.duree_flux' => 0]);
    }

    private function diffuseur(): Diffuseur
    {
        return app(Diffuseur::class);
    }

    public function test_un_evenement_va_dans_la_boite_de_son_destinataire_seulement(): void
    {
        $a = $this->unClient();
        $b = $this->unClient();

        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Bonjour']);

        $this->assertSame(1, DB::table('evenements_temps_reel')->where('user_id', $a->id)->count());
        $this->assertSame(0, DB::table('evenements_temps_reel')->where('user_id', $b->id)->count());
        $this->assertSame('Bonjour', $this->diffuseur()->depuis($a->id, 0)->first()->donnees ? json_decode($this->diffuseur()->depuis($a->id, 0)->first()->donnees, true)['titre'] : null);
        $this->assertCount(0, $this->diffuseur()->depuis($b->id, 0));
    }

    public function test_on_ne_relit_que_ce_qui_suit_le_dernier_numero_connu(): void
    {
        $a = $this->unClient();
        $this->diffuseur()->vers($a, 'commande', ['n' => 1]);
        $premier = $this->diffuseur()->dernierId($a->id);
        $this->diffuseur()->vers($a, 'commande', ['n' => 2]);

        $suite = $this->diffuseur()->depuis($a->id, $premier);

        $this->assertCount(1, $suite);
        $this->assertSame(2, json_decode($suite->first()->donnees, true)['n']);
    }

    public function test_la_purge_retire_les_vieux_evenements_seulement(): void
    {
        $a = $this->unClient();
        $this->diffuseur()->vers($a, 'commande', []);
        DB::table('evenements_temps_reel')->update(['created_at' => now()->subHours(5)]);
        $this->diffuseur()->vers($a, 'commande', []);

        $this->assertSame(1, $this->diffuseur()->purger(120));
        $this->assertSame(1, DB::table('evenements_temps_reel')->count());
    }

    public function test_le_flux_est_reserve_aux_personnes_connectees(): void
    {
        $this->get(route('temps-reel'))->assertRedirect(route('connexion'));
        $this->getJson(route('temps-reel.sonder'))->assertUnauthorized();
    }

    public function test_le_flux_envoie_un_accueil_puis_les_evenements_de_la_personne(): void
    {
        $a = $this->unClient();
        $b = $this->unClient();
        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Pour A']);
        $this->diffuseur()->vers($b, 'notification', ['titre' => 'Pour B']);

        $reponse = $this->actingAs($a)->get(route('temps-reel', ['depuis' => 0]));

        $reponse->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $contenu = $reponse->streamedContent();

        $this->assertStringContainsString('"type":"bonjour"', $contenu);
        $this->assertStringContainsString('"type":"notification"', $contenu);
        $this->assertStringContainsString('Pour A', $contenu);
        $this->assertStringNotContainsString('Pour B', $contenu); // jamais l'événement d'un autre
        $this->assertMatchesRegularExpression('/^id: \d+$/m', $contenu);
    }

    public function test_sans_numero_de_depart_on_ne_rejoue_pas_le_passe(): void
    {
        $a = $this->unClient();
        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Ancienne']);

        $contenu = $this->actingAs($a)->get(route('temps-reel'))->streamedContent();

        $this->assertStringNotContainsString('Ancienne', $contenu);
        // L'accueil donne pourtant le numéro de départ : la reconnexion n'a aucun trou.
        $this->assertStringContainsString('"id":'.$this->diffuseur()->dernierId($a->id), $contenu);
    }

    public function test_le_numero_de_reprise_peut_venir_de_l_en_tete_last_event_id(): void
    {
        $a = $this->unClient();
        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Vue']);
        $vu = $this->diffuseur()->dernierId($a->id);
        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Manquee']);

        $contenu = $this->actingAs($a)->get(route('temps-reel'), ['Last-Event-ID' => (string) $vu])->streamedContent();

        $this->assertStringContainsString('Manquee', $contenu);
        $this->assertStringNotContainsString('Vue', $contenu);
    }

    public function test_le_flux_desactive_annonce_l_arret(): void
    {
        config(['koudmain.temps_reel.actif' => false]);

        $contenu = $this->actingAs($this->unClient())->get(route('temps-reel'))->streamedContent();

        $this->assertStringContainsString('"type":"arret"', $contenu);
    }

    public function test_le_flux_marque_la_personne_comme_presente(): void
    {
        $a = $this->unClient();
        $this->assertFalse($this->diffuseur()->estPresent($a->id));

        $this->actingAs($a)->get(route('temps-reel'))->streamedContent();

        $this->assertTrue($this->diffuseur()->estPresent($a->id));
    }

    public function test_le_rattrapage_en_json_donne_les_evenements_depuis_un_numero(): void
    {
        $a = $this->unClient();
        $this->diffuseur()->vers($a, 'commande', ['commande_id' => 7]);
        $this->diffuseur()->vers($a, 'notification', ['titre' => 'Deux']);

        $reponse = $this->actingAs($a)->getJson(route('temps-reel.sonder', ['depuis' => 0]))->assertOk();

        $reponse->assertJsonCount(2, 'evenements')->assertJsonPath('evenements.0.type', 'commande')->assertJsonPath('evenements.0.donnees.commande_id', 7);
        $this->assertSame($this->diffuseur()->dernierId($a->id), $reponse->json('dernier'));

        // Et rien de plus si l'on donne le dernier numéro.
        $this->actingAs($a)->getJson(route('temps-reel.sonder', ['depuis' => $reponse->json('dernier')]))->assertJsonCount(0, 'evenements');
    }

    public function test_les_pages_de_l_espace_annoncent_le_flux_seulement_si_le_temps_reel_est_actif(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->get(route('client.tableau-de-bord'))->assertOk()
            ->assertSee('data-temps-reel="/temps-reel"', false)->assertSee('data-utilisateur="'.$client->id.'"', false);

        config(['koudmain.temps_reel.actif' => false]);
        $this->actingAs($client)->get(route('client.tableau-de-bord'))->assertOk()->assertDontSee('data-temps-reel=', false);
    }

    public function test_un_utilisateur_supprime_emporte_ses_evenements(): void
    {
        $a = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->diffuseur()->vers($a, 'commande', []);

        $a->delete();

        $this->assertSame(0, DB::table('evenements_temps_reel')->count());
    }
}
