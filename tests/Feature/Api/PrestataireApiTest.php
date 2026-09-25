<?php

namespace Tests\Feature\Api;

use App\Enums\ActionCommande;
use App\Models\Commande;
use App\Models\Disponibilite;
use App\Models\User;
use App\Services\CommandeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** API mobile, Phase 9 : tableau de bord et semaine type du prestataire (écrans 16 et 18 du prototype). */
class PrestataireApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        $this->figerLeTemps(); // lundi 21 septembre 2026, 09:00
    }

    private function verifie(User $u): User
    {
        $u->forceFill(['telephone_verifie_at' => now()])->save();

        return $u;
    }

    /** Une commande menée jusqu'au bout : acceptée, démarrée, terminée, validée (séquestre libéré). */
    private function commandePayee(User $pro, int $prix, ?CarbonImmutable $le = null): Commande
    {
        $service = app(CommandeService::class);
        $client = $this->unClient($prix);
        $c = $this->commander($client, $this->uneOffre($pro, prix: $prix));
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $action) {
            $c = $service->agir($c, $pro, $action);
        }
        $c = $service->agir($c, $client, ActionCommande::ConfirmerReception);

        if ($le !== null) {
            $c->escrow()->update(['libere_at' => $le]);
        }

        return $c;
    }

    public function test_tableau_de_bord_revenus_semaine_sequestre_et_nouvelles(): void
    {
        $pro = $this->verifie($this->unPrestataire());

        $this->commandePayee($pro, 20000, CarbonImmutable::parse('2026-08-10 10:00')); // août
        $this->commandePayee($pro, 15000, CarbonImmutable::parse('2026-09-02 10:00')); // septembre, semaine précédente
        $this->commandePayee($pro, 8000); // aujourd'hui (lundi 21)

        // En séquestre : une demande en attente (bloquée) et une mission terminée à valider.
        $this->commander($this->unClient(12000), $this->uneOffre($pro, prix: 12000));
        $service = app(CommandeService::class);
        $aValider = $this->commander($this->unClient(9000), $this->uneOffre($pro, prix: 9000));
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $action) {
            $aValider = $service->agir($aValider, $pro, $action);
        }

        Sanctum::actingAs($pro);
        $r = $this->getJson('/api/v1/prestataire/tableau')->assertOk()
            ->assertJsonPath('data.revenus.mois', 'septembre')
            ->assertJsonPath('data.revenus.mois_precedent', 'août')
            ->assertJsonPath('data.revenus.montant', 23000)
            ->assertJsonPath('data.revenus.montant_mois_precedent', 20000)
            ->assertJsonPath('data.revenus.variation_pourcent', 15)
            ->assertJsonPath('data.sequestre.montant', 21000)
            ->assertJsonPath('data.sequestre.commandes', 2)
            ->assertJsonPath('data.sequestre.a_valider', 1)
            ->assertJsonPath('data.nouvelles_demandes', 1)
            ->assertJsonPath('data.note.moyenne', null)
            ->assertJsonPath('data.note.avis', 0);

        $semaine = $r->json('data.semaine');
        $this->assertCount(7, $semaine);
        $this->assertSame(['L', 'M', 'M', 'J', 'V', 'S', 'D'], array_column($semaine, 'libelle'));
        $this->assertSame('2026-09-21', $semaine[0]['date']);
        $this->assertTrue($semaine[0]['aujourdhui']);
        $this->assertSame(8000, $semaine[0]['montant']);
        $this->assertSame(0, $semaine[1]['montant']);
    }

    public function test_tableau_reserve_aux_prestataires_valides(): void
    {
        Sanctum::actingAs($this->unClient());
        $this->getJson('/api/v1/prestataire/tableau')->assertForbidden()->assertJsonPath('code', 'role_requis');

        $enAttente = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]);
        Sanctum::actingAs($enAttente);
        $this->getJson('/api/v1/prestataire/disponibilites')->assertForbidden()->assertJsonPath('code', 'prestataire_non_valide');
    }

    public function test_semaine_type_vide_puis_enregistree(): void
    {
        $pro = $this->verifie($this->unPrestataire());
        Sanctum::actingAs($pro);

        $this->getJson('/api/v1/prestataire/disponibilites')->assertOk()
            ->assertJsonPath('data.defini', false)
            ->assertJsonPath('data.par_defaut.debut', '07:00')
            ->assertJsonPath('data.plages_max', 3)
            ->assertJsonCount(7, 'data.jours')
            ->assertJsonPath('data.jours.0.nom', 'Lundi')
            ->assertJsonPath('data.jours.0.plages', []);

        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => [
            '1' => [['13:00', '17:00'], ['08:00', '12:00']],
            '3' => [['08:00', '12:00'], ['13:00', '17:00'], ['18:00', '21:00']],
            '7' => [],
        ]])->assertOk()
            ->assertJsonPath('message', 'Disponibilités enregistrées')
            ->assertJsonPath('data.defini', true)
            ->assertJsonPath('data.jours.0.plages', [['debut' => '08:00', 'fin' => '12:00'], ['debut' => '13:00', 'fin' => '17:00']])
            ->assertJsonPath('data.jours.1.plages', [])
            ->assertJsonCount(3, 'data.jours.2.plages');

        $this->assertSame(5, Disponibilite::query()->where('user_id', $pro->id)->count());

        // Remplacement complet : seul le vendredi soir reste ouvert.
        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['5' => [['18:00', '21:00']]]])->assertOk();
        $this->assertSame(1, Disponibilite::query()->where('user_id', $pro->id)->count());
    }

    public function test_semaine_type_refuse_les_saisies_invalides(): void
    {
        Sanctum::actingAs($this->verifie($this->unPrestataire()));

        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => []])
            ->assertStatus(422)->assertJsonPath('code', 'validation')
            ->assertJsonPath('message', 'Ouvrez au moins un créneau pour recevoir des réservations.');

        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['1' => [['12:00', '08:00']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['jours.1'], 'errors');

        $erreurs = $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['2' => [['08:00', '13:00'], ['12:00', '17:00']]]])
            ->assertStatus(422)->json('errors');
        $this->assertSame('Mardi : deux plages se chevauchent.', $erreurs['jours.2'][0]);

        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['1' => [['7h', '12:00']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['jours.1'], 'errors');

        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['9' => [['08:00', '12:00']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['jours'], 'errors');

        $quatre = [['06:00', '07:00'], ['08:00', '09:00'], ['10:00', '11:00'], ['12:00', '13:00']];
        $erreurs = $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['4' => $quatre]])->assertStatus(422)->json('errors');
        $this->assertSame('Jeudi : 3 plages au maximum.', $erreurs['jours.4'][0]);
    }

    public function test_enregistrer_exige_un_numero_verifie(): void
    {
        Sanctum::actingAs($this->unPrestataire()); // téléphone non vérifié
        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['1' => [['08:00', '12:00']]]])
            ->assertForbidden()->assertJsonPath('code', 'telephone_non_verifie');
    }

    public function test_les_creneaux_proposes_aux_clients_suivent_la_semaine_type(): void
    {
        $pro = $this->verifie($this->unPrestataire());
        $offre = $this->uneOffre($pro, prix: 5000, duree: 60);
        Sanctum::actingAs($pro);
        // Mardi 22 septembre seulement, le matin.
        $this->putJson('/api/v1/prestataire/disponibilites', ['jours' => ['2' => [['08:00', '12:00']]]])->assertOk();

        $jours = $this->getJson("/api/v1/prestations/{$offre->slug}/creneaux")->assertOk()->json('data.jours');
        $this->assertNotEmpty($jours);
        foreach ($jours as $jour) {
            $this->assertSame(2, CarbonImmutable::parse($jour['date'])->dayOfWeekIso); // uniquement des mardis
        }
        $this->assertSame('2026-09-22', $jours[0]['date']);
        $this->assertSame('08:00', $jours[0]['heures'][0]);
        $this->assertSame('11:00', end($jours[0]['heures']));
    }
}
