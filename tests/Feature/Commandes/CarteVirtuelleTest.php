<?php

namespace Tests\Feature\Commandes;

use App\Exceptions\OperationRefusee;
use App\Models\CarteVirtuelle;
use App\Models\Commande;
use App\Models\Paiement;
use App\Models\WalletTransaction;
use App\Enums\ModePaiement;
use App\Services\CarteVirtuelleService;
use App\Services\CommandeService;
use App\Services\PaiementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreeDesCartes;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les cartes bancaires saisies : ajout contrôlé, limite, doublon, gel, suppression, et leur rôle dans les paiements. */
class CarteVirtuelleTest extends TestCase
{
    use CreeDesCartes;
    use CreeDesCommandes;
    use RefreshDatabase;

    private CarteVirtuelleService $cartes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        $this->cartes = app(CarteVirtuelleService::class);
    }

    private function refus(callable $appel, string $extrait): void
    {
        try {
            $appel();
            $this->fail('Une OperationRefusee était attendue.');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString($extrait, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ Un nouvel utilisateur n'a pas de carte

    public function test_un_nouvel_utilisateur_n_a_aucune_carte(): void
    {
        $client = $this->unClient();

        $this->assertCount(0, $this->cartes->pour($client));
        $this->assertCount(0, $this->cartes->pour($client), 'aucune carte n\'est créée en douce, même au deuxième appel');
        $this->assertSame(0, CarteVirtuelle::query()->count());
    }

    public function test_la_premiere_carte_ajoutee_devient_la_carte_par_defaut(): void
    {
        $client = $this->unClient();

        $premiere = $this->uneCarte($client);
        $seconde = $this->uneCarte($client, ['numero' => self::MASTERCARD]);

        $this->assertTrue($premiere->est_principale);
        $this->assertFalse($seconde->est_principale);
        $this->assertSame([$premiere->id, $seconde->id], $this->cartes->pour($client)->pluck('id')->all());
    }

    // ------------------------------------------------------------------ Ce qui est gardé, et ce qui ne l'est jamais

    public function test_la_carte_garde_le_reseau_les_quatre_derniers_chiffres_le_titulaire_et_l_adresse(): void
    {
        $client = $this->unClient();

        $carte = $this->uneCarte($client, ['prenom' => 'awa', 'nom' => 'koné', 'expiration' => '09 / 2029', 'libelle' => '  Ma   carte ']);

        $this->assertSame('visa', $carte->type_carte);
        $this->assertSame('**** **** **** 4242', $carte->numero_masque);
        $this->assertSame('AWA KONÉ', $carte->nom_titulaire);
        $this->assertSame('09/29', $carte->date_expiration);
        $this->assertSame('Rue des Jardins, Cocody, Abidjan, Côte d\'Ivoire', $carte->adresse_facturation);
        $this->assertSame('Ma carte', $carte->libelle);
        $this->assertFalse($carte->est_gelee);
    }

    public function test_sans_nom_choisi_la_carte_s_appelle_reseau_et_fin_de_numero(): void
    {
        $this->assertSame('Visa 4242', $this->uneCarte($this->unClient())->libelle);
        $this->assertSame('American Express 0005', $this->uneCarte($this->unClient(), ['numero' => self::AMEX, 'cvv' => '1234'])->libelle);
    }

    public function test_le_numero_complet_et_le_code_de_securite_ne_sont_jamais_enregistres(): void
    {
        $client = $this->unClient();
        $carte = $this->uneCarte($client, ['cvv' => '987']);

        $ligne = json_encode(DB::table('cartes_virtuelles')->where('id', $carte->id)->first(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::VISA, $ligne);
        $this->assertStringNotContainsString('987', $ligne);
        $this->assertStringNotContainsString('4242424242424242', $ligne);
        $this->assertSame(64, strlen($carte->empreinte));
        $this->assertArrayNotHasKey('empreinte', $carte->toArray(), 'l\'empreinte ne sort jamais dans une réponse');
        $this->assertArrayNotHasKey('cvv', $carte->toArray());
    }

    public function test_le_journal_ne_contient_ni_le_numero_ni_le_code(): void
    {
        $journal = [];
        \Illuminate\Support\Facades\Log::listen(function ($m) use (&$journal): void {
            $journal[] = $m->message.' '.json_encode($m->context);
        });

        $this->uneCarte($this->unClient(), ['cvv' => '987']);

        $texte = implode("\n", $journal);
        $this->assertStringContainsString('carte.ajoutee', $texte);
        $this->assertStringNotContainsString('4242424242424242', $texte);
        $this->assertStringNotContainsString('987', $texte);
    }

    // ------------------------------------------------------------------ Contrôles

    public function test_les_donnees_d_une_carte_sont_controlees(): void
    {
        $client = $this->unClient();

        $this->refus(fn () => $this->uneCarte($client, ['numero' => '6011111111111117']), 'Visa, Mastercard et American Express');
        $this->refus(fn () => $this->uneCarte($client, ['numero' => '4242424242424241']), 'pas valide');
        $this->refus(fn () => $this->uneCarte($client, ['numero' => '424242424242424']), 'pas valide');
        $this->refus(fn () => $this->uneCarte($client, ['cvv' => '12']), '3 chiffres');
        $this->refus(fn () => $this->uneCarte($client, ['numero' => self::AMEX, 'cvv' => '123']), '4 chiffres');
        $this->refus(fn () => $this->uneCarte($client, ['expiration' => '08/26']), 'expiration');
        $this->refus(fn () => $this->uneCarte($client, ['expiration' => '1229']), 'expiration');
        $this->refus(fn () => $this->uneCarte($client, ['couleur' => 'rose-fluo']), 'couleur');
        $this->refus(fn () => $this->uneCarte($client, ['prenom' => 'Awa', 'nom' => '']), 'prénom ET le nom');
        $this->refus(fn () => $this->uneCarte($client, ['libelle' => str_repeat('a', 31)]), 'entre 2 et 30');

        $this->assertSame(0, CarteVirtuelle::query()->count(), 'aucun refus n\'a laissé de carte à moitié créée');
    }

    public function test_le_meme_numero_ne_s_ajoute_pas_deux_fois_mais_un_autre_utilisateur_le_peut(): void
    {
        $client = $this->unClient();
        $this->uneCarte($client);

        $this->refus(fn () => $this->uneCarte($client, ['numero' => '4242 4242 4242 4242']), 'déjà enregistrée');

        $this->assertCount(1, $this->cartes->pour($client));
        $this->assertNotNull($this->uneCarte($this->unClient()));
    }

    public function test_on_enregistre_jusqu_a_cinq_cartes_puis_c_est_refuse(): void
    {
        $client = $this->unClient();
        $numeros = [self::VISA, self::MASTERCARD, '4000056655665556', '5200828282828210', '4111111111111111'];

        foreach ($numeros as $numero) {
            $this->uneCarte($client, ['numero' => $numero]);
        }

        $this->assertCount(5, $this->cartes->pour($client));
        $this->refus(fn () => $this->uneCarte($client, ['numero' => '5105105105105100']), 'plus de 5 cartes');
    }

    // ------------------------------------------------------------------ Gel

    public function test_geler_puis_degeler(): void
    {
        $client = $this->unClient();
        $carte = $this->uneCarte($client);

        $this->assertTrue($this->cartes->basculerGel($client, $carte->id)->est_gelee);
        $this->assertFalse($this->cartes->basculerGel($client, $carte->id)->est_gelee);
    }

    public function test_une_carte_gelee_refuse_de_payer_et_de_recharger(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient(50000);
        $carte = $this->uneCarte($client);
        $this->cartes->basculerGel($client, $carte->id);

        $this->refus(fn () => app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, $carte->id, ModePaiement::Carte), 'gelée');
        $this->assertEquals(50000, $this->solde($client), 'rien n\'a été débité');
        $this->assertSame(0, Commande::query()->count());

        $this->refus(fn () => app(PaiementService::class)->recharger($client, 1000, 'Carte bancaire', null, $carte->id), 'gelée');
    }

    public function test_une_carte_gelee_ne_bloque_pas_un_paiement_physique_ni_mobile_money(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient(50000);
        $carte = $this->uneCarte($client);
        $this->cartes->basculerGel($client, $carte->id);

        $physique = app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, null, ModePaiement::Physique);
        $mobile = app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, null, ModePaiement::MobileMoney);

        $this->assertNotNull($physique->id);
        $this->assertNotNull($mobile->id);
        $this->assertNull(WalletTransaction::query()->where('commande_id', $mobile->id)->value('carte_id'), 'Mobile Money n\'utilise aucune carte');
    }

    // ------------------------------------------------------------------ Paiements

    public function test_payer_par_carte_range_le_paiement_sur_la_carte_choisie(): void
    {
        $client = $this->unClient(50000);
        $this->uneCarte($client);
        $seconde = $this->uneCarte($client, ['numero' => self::MASTERCARD]);

        $commande = app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, $seconde->id, ModePaiement::Carte);

        $ligne = WalletTransaction::query()->where('commande_id', $commande->id)->where('type', 'debit')->firstOrFail();
        $this->assertSame($seconde->id, $ligne->carte_id);
        $this->assertSame(ModePaiement::Carte, $commande->mode_paiement);
    }

    public function test_sans_choix_c_est_la_carte_par_defaut_qui_paie(): void
    {
        $client = $this->unClient(50000);
        $principale = $this->uneCarte($client);
        $this->uneCarte($client, ['numero' => self::MASTERCARD]);

        $commande = app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, null, ModePaiement::Carte);

        $this->assertSame($principale->id, WalletTransaction::query()->where('commande_id', $commande->id)->where('type', 'debit')->value('carte_id'));
    }

    public function test_payer_par_carte_sans_aucune_carte_est_refuse_clairement(): void
    {
        $client = $this->unClient(50000);

        $this->refus(fn () => app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, null, ModePaiement::Carte), 'aucune carte');
        $this->assertEquals(50000, $this->solde($client));
    }

    public function test_une_carte_qui_n_est_pas_a_vous_est_introuvable(): void
    {
        $client = $this->unClient(50000);
        $this->uneCarte($client);
        $carteDeLAutre = $this->uneCarte($this->unClient());

        $this->refus(fn () => app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, $carteDeLAutre->id, ModePaiement::Carte), 'introuvable');
        $this->assertEquals(50000, $this->solde($client));
    }

    public function test_la_recharge_par_mobile_money_n_a_pas_besoin_de_carte(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();

        $paiement = app(PaiementService::class)->recharger($client, 10000, 'Wave', '0701020304');

        $this->assertSame(Paiement::REUSSI, $paiement->statut);
        $this->assertNull($paiement->carte_id);
        $this->assertEquals(10000, $this->solde($client));
    }

    public function test_la_recharge_par_carte_bancaire_exige_une_carte_et_s_y_rattache(): void
    {
        config(['koudmain.paiement.driver' => 'simulation']);
        $client = $this->unClient();

        $this->refus(fn () => app(PaiementService::class)->recharger($client, 10000, 'Carte bancaire'), 'aucune carte');
        $this->assertEquals(0, $this->solde($client));

        $carte = $this->uneCarte($client);
        $paiement = app(PaiementService::class)->recharger($client, 10000, 'Carte bancaire', null, $carte->id);

        $this->assertSame(Paiement::REUSSI, $paiement->statut);
        $this->assertSame($carte->id, $paiement->carte_id);
        $this->assertSame($carte->id, WalletTransaction::query()->where('type', 'credit')->latest('id')->value('carte_id'));
        $this->assertEquals(10000, $this->solde($client));
    }

    // ------------------------------------------------------------------ Suppression

    public function test_supprimer_une_carte_garde_l_historique(): void
    {
        $client = $this->unClient(50000);
        $this->uneCarte($client);
        $seconde = $this->uneCarte($client, ['numero' => self::MASTERCARD]);
        $commande = app(CommandeService::class)->commander($client, $this->uneOffre(), 1, $this->demain(), null, null, $seconde->id, ModePaiement::Carte);

        $this->cartes->supprimer($client, $seconde->id);

        $this->assertNull(CarteVirtuelle::query()->find($seconde->id));
        $ligne = WalletTransaction::query()->where('commande_id', $commande->id)->where('type', 'debit')->firstOrFail();
        $this->assertNull($ligne->carte_id, 'la ligne d\'historique existe toujours, sans carte');
    }

    public function test_supprimer_la_carte_par_defaut_promeut_la_plus_ancienne_des_restantes(): void
    {
        $client = $this->unClient();
        $premiere = $this->uneCarte($client);
        $seconde = $this->uneCarte($client, ['numero' => self::MASTERCARD]);
        $troisieme = $this->uneCarte($client, ['numero' => '4111111111111111']);

        $this->cartes->supprimer($client, $premiere->id);

        $this->assertTrue($seconde->fresh()->est_principale);
        $this->assertFalse($troisieme->fresh()->est_principale);
        $this->assertCount(2, $this->cartes->pour($client));
    }

    public function test_supprimer_la_derniere_carte_laisse_le_wallet_sans_carte(): void
    {
        $client = $this->unClient();
        $carte = $this->uneCarte($client);

        $this->cartes->supprimer($client, $carte->id);

        $this->assertCount(0, $this->cartes->pour($client));
    }

    public function test_on_ne_touche_pas_a_la_carte_d_un_autre(): void
    {
        $client = $this->unClient();
        $carteAutre = $this->uneCarte($this->unClient());

        $this->refus(fn () => $this->cartes->basculerGel($client, $carteAutre->id), 'introuvable');
        $this->refus(fn () => $this->cartes->supprimer($client, $carteAutre->id), 'introuvable');
        $this->assertNotNull(CarteVirtuelle::query()->find($carteAutre->id));
    }

    public function test_la_base_refuse_deux_cartes_principales_dans_un_wallet(): void
    {
        $client = $this->unClient();
        $this->uneCarte($client);
        $wallet = app(\App\Services\WalletService::class)->pour($client);

        $this->expectException(QueryException::class);

        $deuxieme = new CarteVirtuelle(['libelle' => 'Bis', 'type_carte' => 'visa', 'couleur' => 'amber', 'numero_masque' => '**** **** **** 0001', 'nom_titulaire' => 'X Y', 'date_expiration' => '01/30']);
        $deuxieme->forceFill(['wallet_id' => $wallet->id, 'est_principale' => true])->save();
    }
}
