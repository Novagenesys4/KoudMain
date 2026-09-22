<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** L'écran d'accueil (lot 6) : ni recherche, ni catalogue ; écran de chargement d'ouverture une seule fois par session de navigation. */
class AccueilTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    public function test_l_accueil_ne_propose_ni_recherche_ni_catalogue(): void
    {
        $page = $this->withUnencryptedCookie('km_intro', '1')->get(route('accueil'))->assertOk();
        $html = $page->getContent();

        $this->assertStringNotContainsString('Essayez', $html);
        $this->assertStringNotContainsString('type=&quot;search&quot;', $html);
        $this->assertStringNotContainsString('&quot;catalogue&quot;', $html, 'ni lien de catalogue dans le menu, ni dans les adresses passées à la page');
        $this->assertStringNotContainsString('Catalogue', $html);
        $this->assertStringContainsString('data-island="Landing"', $html);
        $this->assertStringContainsString('Domaines', $html);
    }

    public function test_un_visiteur_connecte_a_un_acces_direct_a_son_espace(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->withUnencryptedCookie('km_intro', '1')->get(route('accueil'))->assertOk()->assertSee('&quot;connecte&quot;:true', false);
    }

    public function test_l_ecran_de_chargement_s_affiche_a_la_premiere_visite(): void
    {
        $this->get(route('accueil'))->assertOk()->assertSee('id="chargement"', false)->assertSee('KoudMain se charge')->assertSee('data-passer', false);
    }

    public function test_l_ecran_de_chargement_ne_revient_pas_une_fois_vu(): void
    {
        $this->withUnencryptedCookie('km_intro', '1')->get(route('accueil'))->assertOk()->assertDontSee('id="chargement"', false);
    }

    public function test_l_ecran_de_chargement_couvre_aussi_la_connexion_et_l_espace(): void
    {
        $this->get(route('connexion'))->assertOk()->assertSee('id="chargement"', false);
        $this->actingAs($this->unClient())->get(route('client.wallet'))->assertOk()->assertSee('id="chargement"', false);
    }

    public function test_l_ecran_de_chargement_est_aussi_masque_sur_la_connexion_une_fois_vu(): void
    {
        $this->withUnencryptedCookie('km_intro', '1')->get(route('connexion'))->assertOk()->assertDontSee('id="chargement"', false);
    }

    public function test_les_boutons_d_envoi_ne_sont_pas_pris_pour_l_ecran_de_chargement(): void
    {
        // Les boutons d'envoi portent data-chargement="Un instant…" (texte pendant l'envoi). L'écran d'ouverture a son propre repère :
        // sinon le script l'attrapait à la place du bouton « Se connecter » et le supprimait.
        foreach (['connexion', 'inscription'] as $page) {
            $html = $this->withUnencryptedCookie('km_intro', '1')->get(route($page))->assertOk()->getContent();
            $this->assertStringContainsString('data-chargement=', $html, "$page : le bouton d'envoi est là");
            $this->assertStringNotContainsString('data-ecran-ouverture', $html);
        }

        $script = file_get_contents(resource_path('js/chargement.js'));
        $this->assertStringContainsString("querySelector('#chargement[data-ecran-ouverture]')", $script);
        $this->assertStringNotContainsString("querySelector('[data-chargement]')", $script);
    }

    public function test_l_ecran_d_ouverture_a_son_propre_repere(): void
    {
        $html = $this->get(route('connexion'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-ecran-ouverture'));
        $this->assertStringContainsString('id="chargement"', $html);
    }

    public function test_le_cookie_du_chargement_est_lisible_tel_quel(): void
    {
        // Posé par le JavaScript (donc en clair) : Laravel ne doit pas le rejeter faute de chiffrement.
        $this->call('GET', route('accueil'), [], ['km_intro' => '1'])->assertOk()->assertDontSee('id="chargement"', false);
    }
}
