<?php

namespace Tests\Feature\Espace;

use App\Models\User;
use App\Support\Espace\Bientot;
use App\Support\Espace\Menu;
use App\Support\Icones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

/** Les trois espaces (client, prestataire, administrateur) : accès par rôle, menu latéral, onglets « bientôt ». */
class EspacesTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private function client(): User
    {
        $client = User::factory()->create(['prenom' => 'Aya', 'quartier_id' => $this->creerQuartier()->id]);
        $client->wallet()->create();

        return $client;
    }

    private function prestataire(): User
    {
        $prestataire = $this->unPrestataire();
        $prestataire->wallet()->create();

        return $prestataire;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    // ----------------------------------------------------------------- Menu par rôle

    public function test_le_client_a_le_menu_de_l_ancienne_application(): void
    {
        $this->actingAs($this->client())->get('/client')->assertOk()
            ->assertSee('Espace client')
            ->assertSeeInOrder(['Vue d\'ensemble', 'Catalogue', 'Mes commandes', 'Mes favoris', 'Mon wallet', 'Mon profil', 'Mot de passe', 'Déconnexion'])
            ->assertDontSee('Prestataires à valider');
    }

    public function test_le_prestataire_a_son_menu(): void
    {
        $this->actingAs($this->prestataire())->get('/prestataire')->assertOk()
            ->assertSee('Espace prestataire')
            ->assertSeeInOrder(['Vue d\'ensemble', 'Mes prestations', 'Commandes', 'Disponibilités', 'Mon wallet', 'Mon profil public', 'Déconnexion']);
    }

    public function test_l_admin_a_son_menu_et_son_tableau_de_bord(): void
    {
        User::factory()->enAttente()->create(['prenom' => 'Kouadio', 'quartier_id' => $this->creerQuartier()->id]);

        $this->actingAs($this->admin())->get('/admin')->assertOk()
            ->assertSeeInOrder(['Tableau de bord', 'Prestataires', 'Utilisateurs', 'Catalogue', 'Commandes', 'Retraits', 'Métriques et santé'])
            ->assertSee('Profils à valider')
            ->assertSee('Kouadio');
    }

    public function test_un_espace_est_interdit_aux_autres_roles(): void
    {
        $client = $this->client();
        $prestataire = $this->prestataire();

        foreach (['/prestataire', '/prestataire/prestations', '/prestataire/commandes', '/admin', '/admin/utilisateurs', '/admin/catalogue', '/admin/commandes', '/admin/prestataires', '/admin/metriques'] as $adresse) {
            $this->actingAs($client)->get($adresse)->assertForbidden();
        }

        foreach (['/client', '/client/catalogue', '/client/commandes', '/client/favoris', '/client/wallet', '/admin', '/admin/prestataires'] as $adresse) {
            $this->actingAs($prestataire)->get($adresse)->assertForbidden();
        }
    }

    public function test_un_visiteur_est_renvoye_vers_la_connexion(): void
    {
        foreach (['/client/catalogue', '/prestataire/commandes', '/admin/utilisateurs', '/messages', '/notifications'] as $adresse) {
            $this->get($adresse)->assertRedirect(route('connexion'));
        }
    }

    // ------------------------------------------------------------ Onglets « bientôt »

    public function test_plus_aucun_onglet_n_est_a_venir(): void
    {
        // Le lot 5 a livré « Métriques et santé » : il ne reste aucun onglet « bientôt » dans les menus.
        $this->assertSame([], Bientot::routes());

        $menu = Menu::pour($this->admin(), 'admin.tableau-de-bord');
        foreach (collect($menu['groupes'])->pluck('liens')->flatten(1) as $lien) {
            $this->assertFalse($lien['bientot'], $lien['libelle'].' est marqué « bientôt ».');
        }
    }

    public function test_un_onglet_devenu_reel_n_est_plus_marque_bientot(): void
    {
        // Le menu marque « Bientôt » uniquement les routes déclarées dans Bientot::PAGES.
        $menu = Menu::pour($this->client(), 'client.tableau-de-bord');
        $liens = collect($menu['groupes'])->pluck('liens')->flatten(1)->keyBy('libelle');

        $this->assertFalse($liens['Mes favoris']['bientot']);   // livré au lot 4
        $this->assertFalse($liens['Messages']['bientot']);
        $this->assertFalse($liens['Mes commandes']['bientot']); // livré au lot 3
        $this->assertFalse($liens['Mon wallet']['bientot']);
        $this->assertFalse($liens['Catalogue']['bientot']);
        $this->assertFalse($liens['Vue d\'ensemble']['bientot']);
    }

    // ---------------------------------------------------------------- Client

    public function test_le_client_parcourt_le_catalogue_dans_son_espace(): void
    {
        $presta = $this->prestataire();
        $service = $this->unService('Plomberie', 'Habitat');
        \App\Models\Prestation::factory()->for($presta, 'prestataire')->create(['service_id' => $service->id, 'titre' => 'Réparation de fuite']);

        $this->actingAs($this->client())->get('/client/catalogue')->assertOk()
            ->assertSee('Espace client')
            ->assertSee('Réparation de fuite')
            ->assertSee('Mes commandes'); // le menu est là : ce n'est pas la page publique
    }

    public function test_le_client_voit_ses_commandes_recentes_sur_sa_vue_d_ensemble(): void
    {
        $client = $this->client();
        $presta = $this->prestataire();
        $prestation = \App\Models\Prestation::factory()->for($presta, 'prestataire')->create(['service_id' => $this->unService()->id, 'titre' => 'Tresses collées']);
        $this->uneCommande($client, $presta, $prestation);

        $this->actingAs($client)->get('/client')->assertOk()->assertSee('Tresses collées')->assertSee('Terminée');
    }

    // ---------------------------------------------------------------- Compte

    public function test_le_profil_et_le_mot_de_passe_s_affichent_dans_l_espace_de_chaque_role(): void
    {
        foreach ([$this->client(), $this->prestataire(), $this->admin()] as $utilisateur) {
            $this->actingAs($utilisateur)->get('/compte/profil')->assertOk()->assertSee($utilisateur->libelleRole());
            $this->actingAs($utilisateur)->get('/compte/mot-de-passe')->assertOk()->assertSee('Mot de passe');
        }
    }

    // ------------------------------------------------------------------ Icônes

    public function test_toutes_les_icones_utilisees_existent(): void
    {
        $utilisees = [];

        foreach (File::allFiles(resource_path('views')) as $fichier) {
            preg_match_all('/<x-icone[^>]*?\snom="([a-z-]+)"/', File::get($fichier->getPathname()), $simples);
            $utilisees = [...$utilisees, ...$simples[1]];
        }

        // Les icônes du menu et des pages « bientôt » sont dans le code PHP.
        foreach ([Menu::pour($this->client(), null), Menu::pour($this->prestataire(), null), Menu::pour($this->admin(), null)] as $menu) {
            foreach ($menu['groupes'] as $groupe) {
                $utilisees = [...$utilisees, ...array_column($groupe['liens'], 'icone')];
            }
        }
        foreach (Bientot::routes() as $route) {
            $utilisees[] = Bientot::page($route)['icone'];
        }
        $utilisees = [...$utilisees, 'oeil', 'oeil-barre']; // choisies dynamiquement dans « Mes prestations »

        $this->assertNotEmpty($utilisees);

        foreach (array_unique($utilisees) as $nom) {
            $this->assertTrue(Icones::existe($nom), "L'icône « $nom » n'existe pas dans App\\Support\\Icones.");
        }
    }
}
