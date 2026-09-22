<?php

namespace Tests\Feature\Commandes;

use App\Services\DisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

class DisponibiliteServiceTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private DisponibiliteService $dispos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps(); // lundi 21 septembre 2026, 09:00
        $this->dispos = app(DisponibiliteService::class);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sans_horaires_on_propose_la_plage_par_defaut(): void
    {
        $jours = $this->dispos->creneaux($this->unPrestataire(), 60);

        $this->assertNotSame([], $jours);
        $aujourdhui = $jours[0];
        $this->assertSame('2026-09-21', $aujourdhui['cle']);
        $this->assertSame('11:00', $aujourdhui['creneaux'][0], 'Délai minimal de 2 h : 09:00 + 2 h = 11:00.');
        $this->assertSame('20:00', end($aujourdhui['creneaux']), 'Une prestation d\'1 h doit finir avant 21:00.');
    }

    public function test_les_horaires_du_prestataire_sont_respectes(): void
    {
        $prestataire = $this->unPrestataire();
        // mardi (2) : 08:00-12:00 et 14:00-16:00 ; aucun autre jour
        $this->dispos->enregistrer($prestataire, [2 => [['08:00', '12:00'], ['14:00', '16:00']]]);

        $jours = $this->dispos->creneaux($prestataire, 60);

        $this->assertSame('2026-09-22', $jours[0]['cle']);
        $this->assertSame(['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '14:00', '14:30', '15:00'], $jours[0]['creneaux']);
        $this->assertSame('2026-09-29', $jours[1]['cle'], 'Le mardi suivant : les autres jours sont fermés.');
    }

    public function test_enregistrer_remplace_toute_la_semaine(): void
    {
        $prestataire = $this->unPrestataire();
        $this->dispos->enregistrer($prestataire, [1 => [['08:00', '12:00']], 3 => [['09:00', '10:00']]]);
        $this->dispos->enregistrer($prestataire, [5 => [['13:00', '17:00']]]);

        $this->assertSame([5 => [['13:00', '17:00']]], $this->dispos->horaires($prestataire));
        $this->assertTrue($this->dispos->aDesHoraires($prestataire));
    }

    public function test_verifier_refuse_ce_qui_est_hors_horaires(): void
    {
        $prestataire = $this->unPrestataire();
        $this->dispos->enregistrer($prestataire, [2 => [['08:00', '12:00']]]);
        $mardi = now()->addDay(); // 22 septembre, mardi

        $this->assertNull($this->dispos->verifier($prestataire, $mardi->copy()->setTime(9, 0), 60));
        $this->assertStringContainsString('pas disponible', $this->dispos->verifier($prestataire, $mardi->copy()->setTime(15, 0), 60));
        $this->assertStringContainsString('pas disponible', $this->dispos->verifier($prestataire, $mardi->copy()->setTime(11, 30), 60), 'La prestation dépasserait la fermeture.');
        $this->assertStringContainsString('pas disponible', $this->dispos->verifier($prestataire, now()->addDays(2)->setTime(9, 0), 60), 'Mercredi : fermé.');
    }

    public function test_une_prestation_plus_longue_que_la_plage_commence_a_l_ouverture(): void
    {
        $prestataire = $this->unPrestataire();
        $this->dispos->enregistrer($prestataire, [2 => [['08:00', '12:00']]]);

        $this->assertNull($this->dispos->verifier($prestataire, now()->addDay()->setTime(8, 0), 1440));
    }
}
