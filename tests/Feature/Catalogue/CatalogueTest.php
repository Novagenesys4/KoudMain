<?php

namespace Tests\Feature\Catalogue;

use App\Models\Prestation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private User $prestataire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prestataire = $this->unPrestataire(['prenom' => 'Mariam', 'nom' => 'Traoré']);
    }

    private function prestation(string $titre, int $prix = 10000, array $autres = [], ?User $prestataire = null): Prestation
    {
        return Prestation::factory()->for($prestataire ?? $this->prestataire, 'prestataire')->create(array_merge([
            'titre' => $titre,
            'prix' => $prix,
            'service_id' => $this->unService()->id,
        ], $autres));
    }

    /** Les titres affichés dans l'ordre, lus dans les données de l'île React. */
    private function titres(string $url): array
    {
        $reponse = $this->get($url)->assertOk();
        $cartes = $reponse->viewData('cartes');

        return array_column($cartes, 'titre');
    }

    public function test_le_catalogue_est_public_et_liste_les_prestations(): void
    {
        $this->prestation('Tresses africaines');

        $this->get('/prestations')->assertOk()->assertSee('Tresses africaines')->assertSee('1 prestation');
    }

    public function test_le_catalogue_vide_le_dit(): void
    {
        $this->get('/prestations')->assertOk()->assertSee('Le catalogue est vide');
    }

    public function test_seules_les_prestations_actives_de_prestataires_valides_apparaissent(): void
    {
        $this->prestation('Visible');
        $this->prestation('Masquée', autres: ['est_active' => false]);
        $this->prestation('Prestataire en attente', prestataire: User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]));

        $this->assertSame(['Visible'], $this->titres('/prestations'));
    }

    public function test_la_recherche_ignore_les_accents_et_les_majuscules(): void
    {
        $this->prestation('Réparation de fuite d\'eau');

        $this->assertSame(['Réparation de fuite d\'eau'], $this->titres('/prestations?q=reparation'));
        $this->assertSame(['Réparation de fuite d\'eau'], $this->titres('/prestations?q=RÉPARATION'));
    }

    public function test_la_recherche_trouve_un_debut_de_mot_et_les_variantes(): void
    {
        $this->prestation('Plomberie et sanitaire');
        $this->prestation('Coiffure à domicile');

        $this->assertSame(['Plomberie et sanitaire'], $this->titres('/prestations?q=plomb'));
        $this->assertSame(['Coiffure à domicile'], $this->titres('/prestations?q=coiffure+domicile'), 'un mot du titre + un mot du titre');
    }

    public function test_la_recherche_porte_aussi_sur_le_service_le_prestataire_et_la_description(): void
    {
        $this->prestation('Séance', autres: ['description' => 'Massage relaxant à domicile']);

        $this->assertCount(1, $this->titres('/prestations?q=massage'));
        $this->assertCount(1, $this->titres('/prestations?q=traore'), 'le nom du prestataire, sans accent');
        $this->assertCount(1, $this->titres('/prestations?q=coiffure'), 'le nom du service');
        $this->assertCount(0, $this->titres('/prestations?q=zzzz'));
    }

    public function test_tous_les_mots_de_la_recherche_doivent_correspondre(): void
    {
        $this->prestation('Tresses africaines');
        $this->prestation('Tresses enfants');

        $this->assertSame(['Tresses africaines'], $this->titres('/prestations?q=tresses+africaines'));
    }

    public function test_une_saisie_hostile_ne_provoque_pas_d_erreur(): void
    {
        $this->prestation('Coiffure');

        foreach (["'; DROP TABLE prestations; --", '%%%', '<script>alert(1)</script>', '!!! ((( ::', str_repeat('a', 500), '\\', '&|!:*'] as $q) {
            $this->get('/prestations?'.http_build_query(['q' => $q]))->assertOk();
        }
        $this->assertSame(1, Prestation::query()->count());
    }

    public function test_filtre_par_prix(): void
    {
        $this->prestation('Économique', 5000);
        $this->prestation('Moyenne', 20000);
        $this->prestation('Chère', 80000);

        $this->assertEqualsCanonicalizing(['Moyenne', 'Chère'], $this->titres('/prestations?prix_min=10000'));
        $this->assertSame(['Économique'], $this->titres('/prestations?prix_max=6000'));
        $this->assertSame(['Moyenne'], $this->titres('/prestations?prix_min=10000&prix_max=30000'));
        $this->assertCount(3, $this->titres('/prestations?prix_min=abc&prix_max='), 'valeurs invalides ignorées');
    }

    public function test_filtre_par_service_et_par_categorie(): void
    {
        $plomberie = $this->unService('Réparation fuite', 'Plomberie et Sanitaire');
        $this->prestation('Coiffure');
        $this->prestation('Fuite', autres: ['service_id' => $plomberie->id]);

        $this->assertSame(['Fuite'], $this->titres('/prestations?service='.$plomberie->id));
        $this->assertSame(['Fuite'], $this->titres('/prestations?categorie='.$plomberie->categorie_id));
        $this->assertSame([], $this->titres('/prestations?categorie=999999'));
    }

    public function test_filtre_par_zone(): void
    {
        $autre = $this->creerQuartier('Marcory');
        $loin = User::factory()->prestataire()->create(['quartier_id' => $autre->id]);
        $this->prestation('Ici');
        $this->prestation('Là-bas', prestataire: $loin);

        $this->assertSame(['Là-bas'], $this->titres('/prestations?zone=q:'.$autre->id));
        $this->assertCount(2, $this->titres('/prestations?zone=v:'.$autre->ville_id), 'toute la ville');
        $this->get('/prestations?zone=n-importe-quoi')->assertOk();
    }

    public function test_filtre_avec_photo(): void
    {
        $this->prestation('Sans photo');
        $this->unePhoto($this->prestation('Avec photo'));

        $this->assertSame(['Avec photo'], $this->titres('/prestations?photo=1'));
    }

    public function test_filtre_par_note_et_tri(): void
    {
        $bonne = $this->prestation('Bonne');
        $moyenne = $this->prestation('Moyenne');
        $this->prestation('Sans avis');
        $this->unAvis($bonne, 5);
        $this->unAvis($bonne, 5);
        $this->unAvis($moyenne, 3);

        $this->assertSame(['Bonne'], $this->titres('/prestations?note_min=4'));
        $this->assertEqualsCanonicalizing(['Bonne', 'Moyenne'], $this->titres('/prestations?note_min=3'));
        $this->assertSame(['Bonne', 'Moyenne'], array_slice($this->titres('/prestations?tri=note'), 0, 2), 'les prestations notées avant celles sans avis');
    }

    public function test_les_tris_par_prix(): void
    {
        $this->prestation('Milieu', 20000);
        $this->prestation('Petit prix', 5000);
        $this->prestation('Grand prix', 90000);

        $this->assertSame(['Petit prix', 'Milieu', 'Grand prix'], $this->titres('/prestations?tri=prix_asc'));
        $this->assertSame(['Grand prix', 'Milieu', 'Petit prix'], $this->titres('/prestations?tri=prix_desc'));
        $this->get('/prestations?tri=hack')->assertOk();
    }

    public function test_le_tri_par_defaut_est_du_plus_recent_au_plus_ancien(): void
    {
        $premiere = $this->prestation('Ancienne');
        $premiere->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->prestation('Récente');

        $this->assertSame(['Récente', 'Ancienne'], $this->titres('/prestations'));
    }

    public function test_pagination(): void
    {
        config(['koudmain.catalogue.par_page' => 2]);
        foreach (range(1, 5) as $n) {
            $this->prestation("Prestation $n");
        }

        $this->assertCount(2, $this->titres('/prestations'));
        $this->assertCount(1, $this->titres('/prestations?page=3'));
        $this->get('/prestations?page=3')->assertOk()->assertSee('5 prestations');
        // La pagination garde les filtres.
        $this->get('/prestations?q=prestation&page=1')->assertSee('q=prestation', false);
    }

    public function test_pas_de_requetes_en_cascade(): void
    {
        foreach (range(1, 8) as $n) {
            $this->unePhoto($this->prestation("Prestation $n", prestataire: $this->unPrestataire()));
        }

        DB::enableQueryLog();
        $this->get('/prestations')->assertOk();
        $nombre = count(DB::getQueryLog());

        // Le nombre de requêtes ne dépend pas du nombre de prestations (lazy loading interdit hors production).
        $this->assertLessThan(20, $nombre, "Trop de requêtes : $nombre");
    }

    public function test_une_page_filtree_n_est_pas_indexee(): void
    {
        $this->prestation('Coiffure');

        $this->get('/prestations')->assertDontSee('noindex', false);
        $this->get('/prestations?q=coiffure')->assertSee('noindex,follow', false);
        $this->get('/prestations?tri=prix_asc')->assertSee('noindex,follow', false);
    }

    public function test_les_suggestions_renvoient_du_json_minimal(): void
    {
        $this->prestation('Tresses africaines', 15000);
        $this->prestation('Masquée', autres: ['est_active' => false]);

        $reponse = $this->getJson('/recherche/suggestions?q=tresse')->assertOk();

        $reponse->assertJsonCount(1, 'prestations')->assertJsonPath('prestations.0.titre', 'Tresses africaines');
        $this->assertSame(['id', 'titre', 'prestataire', 'quartier', 'metier', 'categorie', 'prix', 'url'], array_keys($reponse->json('prestations.0')));
        $this->getJson('/recherche/suggestions?q=a')->assertOk()->assertJsonCount(0, 'prestations');
        $this->getJson('/recherche/suggestions')->assertOk()->assertJsonCount(0, 'prestations');
    }

    public function test_l_accueil_montre_les_dernieres_prestations_reelles(): void
    {
        $this->prestation('Coiffure de mariage');

        $reponse = $this->get('/')->assertOk();
        $this->assertSame('Coiffure de mariage', $reponse->viewData('prestations')[0]['titre']);
    }
}
