<?php

namespace Tests\Feature\Commandes;

use App\Enums\ActionCommande;
use App\Enums\StatutCommande;
use App\Models\CarteVirtuelle;
use App\Services\CommandeService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreeDesCartes;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les pages des cartes (wallet client et prestataire) : formulaire « Ajouter une carte », gel, suppression, sécurité des données saisies. */
class CarteVirtuelleEspaceTest extends TestCase
{
    use CreeDesCartes;
    use CreeDesCommandes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        config(['koudmain.paiement.driver' => 'simulation']);
    }

    public function test_un_nouveau_client_voit_un_wallet_sans_carte_et_une_invitation_a_en_ajouter(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->get(route('client.wallet'))->assertOk()
            ->assertSee('Aucune carte pour le moment')->assertSee('Ajouter une carte')->assertSee('id="carte"', false)
            ->assertDontSee('data-island="CartesWallet"', false);
        $this->assertSame(0, CarteVirtuelle::query()->count(), 'ouvrir le wallet ne crée aucune carte');
    }

    public function test_le_wallet_du_client_montre_ses_cartes_et_le_solde(): void
    {
        $client = $this->unClient(50000);
        $this->uneCarte($client, ['libelle' => 'Saphir']);

        $this->actingAs($client)->get(route('client.wallet'))->assertOk()
            ->assertSee('Vos cartes')->assertSee('Saphir')->assertSee('data-island="CartesWallet"', false)
            ->assertSee('name="carte_id"', false)->assertSee("50\u{202F}000");
    }

    public function test_le_wallet_du_prestataire_a_aussi_ses_cartes(): void
    {
        $prestataire = $this->unPrestataire();
        $this->uneCarte($prestataire, ['libelle' => 'Réserve']);

        $this->actingAs($prestataire)->get(route('prestataire.wallet'))->assertOk()->assertSee('Vos cartes')->assertSee('Réserve');
    }

    public function test_le_formulaire_demande_tous_les_champs_de_la_carte(): void
    {
        $page = $this->actingAs($this->unClient())->get(route('client.wallet'))->assertOk();

        foreach (['numero_carte', 'expiration', 'cvv', 'prenom', 'nom', 'adresse', 'ville', 'pays'] as $champ) {
            $page->assertSee('name="'.$champ.'"', false);
        }
        $page->assertSee('MM/AA')->assertSee('autocomplete="cc-number"', false)->assertSee('autocomplete="cc-csc"', false);
    }

    public function test_on_ajoute_gele_et_supprime_une_carte_depuis_la_page(): void
    {
        $client = $this->unClient();
        $this->actingAs($client);

        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['libelle' => 'Courses', 'numero_carte' => self::MASTERCARD, 'couleur' => 'amber']))
            ->assertSessionHasNoErrors()->assertSessionHas('succes');
        $carte = CarteVirtuelle::query()->where('libelle', 'Courses')->firstOrFail();
        $this->assertSame('mastercard', $carte->type_carte);
        $this->assertTrue($carte->est_principale, 'la première carte est la carte par défaut');

        $this->patch(route('client.wallet.cartes.geler', $carte->id))->assertRedirect(route('client.wallet', ['carte' => $carte->id]))->assertSessionHas('succes');
        $this->assertTrue($carte->fresh()->est_gelee);
        $this->get(route('client.wallet', ['carte' => $carte->id]))->assertOk()->assertSee('&quot;gelee&quot;:true', false);

        $this->patch(route('client.wallet.cartes.geler', $carte->id));
        $this->assertFalse($carte->fresh()->est_gelee);

        $this->delete(route('client.wallet.cartes.supprimer', $carte->id))->assertSessionHas('succes');
        $this->assertNull(CarteVirtuelle::query()->find($carte->id));
    }

    public function test_le_gel_repond_en_json_pour_l_animation(): void
    {
        $client = $this->unClient();
        $carte = $this->uneCarte($client);

        $this->actingAs($client)->patchJson(route('client.wallet.cartes.geler', $carte->id))
            ->assertOk()->assertJson(['id' => $carte->id, 'gelee' => true])->assertJsonStructure(['message']);
        $this->patchJson(route('client.wallet.cartes.geler', $carte->id))->assertOk()->assertJson(['gelee' => false]);

        $autre = $this->uneCarte($this->unClient());
        $this->patchJson(route('client.wallet.cartes.geler', $autre->id))->assertStatus(422)->assertJsonStructure(['erreur']);
        $this->assertFalse($autre->fresh()->est_gelee);
    }

    public function test_une_carte_amex_accepte_ses_15_chiffres_et_son_code_a_4_chiffres(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => '3782 822463 10005', 'cvv' => '1234']))
            ->assertSessionHasNoErrors()->assertSessionHas('succes');

        $this->assertSame('amex', CarteVirtuelle::query()->firstOrFail()->type_carte);
    }

    public function test_une_saisie_invalide_est_refusee_avec_les_messages_de_chaque_champ(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $client = $this->unClient();
        $this->actingAs($client);

        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => '4242424242424241']))->assertSessionHasErrors(['numero_carte']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => '6011111111111117']))->assertSessionHasErrors(['numero_carte']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => '424242424242424']))->assertSessionHasErrors(['numero_carte']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['expiration' => '08/26']))->assertSessionHasErrors(['expiration']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['expiration' => '1229']))->assertSessionHasErrors(['expiration']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '12']))->assertSessionHasErrors(['cvv']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => self::AMEX, 'cvv' => '123']))->assertSessionHasErrors(['cvv']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['prenom' => '', 'nom' => '']))->assertSessionHasErrors(['prenom', 'nom']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['prenom' => 'R2D2']))->assertSessionHasErrors(['prenom']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['adresse' => '', 'ville' => '', 'pays' => '']))->assertSessionHasErrors(['adresse', 'ville', 'pays']);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['couleur' => 'rose-fluo']))->assertSessionHasErrors(['couleur']);

        $this->assertSame(0, CarteVirtuelle::query()->count());
    }

    public function test_un_refus_rouvre_la_boite_avec_un_bandeau_et_des_champs_en_erreur(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['expiration' => '08/26', 'cvv' => '12']))
            ->assertRedirect(route('client.wallet'));

        $page = $this->get(route('client.wallet'))->assertOk();
        // La boîte se rouvre seule, avec un bandeau d'erreur DANS la boîte (la page derrière est masquée), et les champs fautifs marqués.
        $page->assertSee('La carte n&#039;a pas été ajoutée.', false)
            ->assertSee('data-dialogue-alerte', false)
            ->assertSee('Cette carte est expirée, ou la date est trop lointaine.', false);
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<input id="carte-expiration" name="expiration"\s+aria-invalid="true"/', $html);
        $this->assertMatchesRegularExpression('/<input id="carte-cvv" name="cvv"\s+aria-invalid="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="carte-prenom" name="prenom"\s+aria-invalid/', $html);
        $this->assertMatchesRegularExpression('/<dialog id="carte"[^>]*data-ouvert-auto/', $html);
        $this->assertSame(0, CarteVirtuelle::query()->count());
    }

    public function test_un_refus_ramene_au_wallet_meme_sans_en_tete_referer(): void
    {
        // Sans « Referer » (vie privée, extension...), Laravel revenait à l'accueil : la personne ne voyait ni carte ni message.
        $this->actingAs($this->unPrestataire())->post(route('prestataire.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '1']))
            ->assertRedirect(route('prestataire.wallet'));
        $this->post(route('prestataire.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '1']), ['Referer' => 'https://autre-site.example/'])
            ->assertRedirect(route('prestataire.wallet'));
    }

    public function test_le_refus_du_service_est_affiche_dans_la_boite_de_la_carte(): void
    {
        $client = $this->unClient();
        $this->uneCarte($client);

        // Même carte ajoutée deux fois : le message du service doit se lire DANS la boîte, pas seulement en haut de la page.
        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte());
        $page = $this->get(route('client.wallet'))->assertOk();

        $this->assertMatchesRegularExpression('/<dialog id="carte"[^>]*data-ouvert-auto/', $page->getContent());
        $this->assertMatchesRegularExpression('/<div class="dialogue-alerte"[^>]*data-dialogue-alerte\s*>\s*<strong[^>]*>[^<]*<\/strong>\s*<ul[^>]*>\s*<li>Cette carte est déjà enregistrée/u', $page->getContent());
    }

    public function test_un_refus_de_carte_laisse_une_trace_sans_numero_ni_code(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['expiration' => '08/26']))->assertSessionHasErrors(['expiration']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('log')->withArgs(function ($niveau, $message, $contexte = []) {
            $texte = $message.json_encode($contexte);

            return str_contains($texte, 'carte.refusee') && str_contains($texte, 'expiration') && ! str_contains($texte, '4242') && ! str_contains($texte, '08/26');
        })->once();
    }

    public function test_une_recharge_refusee_rouvre_la_boite_et_dit_pourquoi(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.recharger'), ['montant' => 'beaucoup', 'methode' => 'Wave'])->assertSessionHasErrors(['montant'])->assertSessionHas('ouvrir', 'recharge');

        $page = $this->get(route('client.wallet'))->assertOk()->assertSee('La recharge n&#039;a pas été faite.', false)->assertSee('Indiquez un montant entier en FCFA', false);
        $this->assertMatchesRegularExpression('/<dialog id="recharge"[^>]*data-ouvert-auto/', $page->getContent());
    }

    public function test_un_retrait_refuse_rouvre_la_boite_et_dit_pourquoi(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 20000, 'Gains');

        $this->actingAs($prestataire)->post(route('prestataire.wallet.retrait'), ['montant' => 5000, 'methode' => 'Wave', 'destination' => ''])->assertSessionHasErrors(['destination'])->assertSessionHas('ouvrir', 'retrait');

        $page = $this->get(route('prestataire.wallet'))->assertOk()->assertSee('Le retrait n&#039;a pas été demandé.', false)->assertSee('Indiquez le numéro ou le RIB', false);
        $this->assertMatchesRegularExpression('/<dialog id="retrait"[^>]*data-ouvert-auto/', $page->getContent());
        $this->assertSame(20000.0, $this->solde($prestataire));
    }

    public function test_les_erreurs_des_boites_ne_sont_plus_grisees_par_le_style_de_la_boite(): void
    {
        // « .dialogue p » (gris) l'emportait sur « .champ-erreur » : les erreurs d'une boîte ressemblaient à une aide. La règle dédiée doit exister.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.dialogue \.champ-erreur\s*\{[^}]*color:\s*var\(--danger\)/', $css);
        $this->assertStringContainsString('.dialogue-alerte', $css);
    }

    public function test_l_ajout_de_cartes_est_limite_en_frequence(): void
    {
        $client = $this->unClient();
        $this->actingAs($client);

        for ($i = 0; $i < 8; $i++) {
            $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '12']));
        }

        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte())->assertStatus(429);
    }

    public function test_le_numero_et_le_code_ne_sont_jamais_rejoues_dans_le_formulaire_apres_une_erreur(): void
    {
        $client = $this->unClient();

        // Erreur de validation (code trop court) : le formulaire est rejoué, sans le numéro ni le code.
        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '12']))
            ->assertSessionHasErrors(['cvv'])
            ->assertSessionHas('_old_input.prenom', 'Awa')
            ->assertSessionMissing('_old_input.numero_carte')->assertSessionMissing('_old_input.cvv');

        // Erreur du service (doublon) : même règle.
        $this->uneCarte($client);
        $this->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['cvv' => '456']))
            ->assertSessionHas('erreur')
            ->assertSessionHas('_old_input.prenom', 'Awa')
            ->assertSessionMissing('_old_input.numero_carte')->assertSessionMissing('_old_input.cvv');
    }

    public function test_les_donnees_sensibles_ne_sont_pas_dans_la_page_du_wallet(): void
    {
        $client = $this->unClient();
        $this->uneCarte($client, ['cvv' => '987']);

        $page = $this->actingAs($client)->get(route('client.wallet'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::VISA, $page);
        $this->assertStringNotContainsString('&quot;cvv&quot;', $page);
        $this->assertStringNotContainsString('&quot;numero&quot;', $page);
        $this->assertStringContainsString('4242', $page, 'seuls les 4 derniers chiffres apparaissent');
    }

    public function test_la_carte_d_un_autre_utilisateur_est_introuvable(): void
    {
        $intrus = $this->unClient();
        $carteAutre = $this->uneCarte($this->unClient());

        $this->actingAs($intrus)->patch(route('client.wallet.cartes.geler', $carteAutre->id))->assertSessionHas('erreur');
        $this->delete(route('client.wallet.cartes.supprimer', $carteAutre->id))->assertSessionHas('erreur');
        $this->assertFalse($carteAutre->fresh()->est_gelee);
        $this->assertNotNull(CarteVirtuelle::query()->find($carteAutre->id));
    }

    public function test_on_ne_peut_pas_ajouter_plus_de_cinq_cartes_depuis_la_page(): void
    {
        $client = $this->unClient();
        foreach ([self::VISA, self::MASTERCARD, '4000056655665556', '5200828282828210', '4111111111111111'] as $numero) {
            $this->uneCarte($client, ['numero' => $numero]);
        }

        $this->actingAs($client)->post(route('client.wallet.cartes.creer'), $this->formulaireCarte(['numero_carte' => '5105105105105100']))->assertSessionHas('erreur');
        $this->assertSame(5, CarteVirtuelle::query()->count());
    }

    public function test_les_cartes_d_un_role_ne_se_pilotent_pas_avec_l_autre(): void
    {
        $this->actingAs($this->unPrestataire())->post(route('client.wallet.cartes.creer'), $this->formulaireCarte())->assertForbidden();
        $this->actingAs($this->unClient())->post(route('prestataire.wallet.cartes.creer'), $this->formulaireCarte())->assertForbidden();
    }

    public function test_une_recharge_par_carte_avec_une_carte_gelee_est_refusee_depuis_la_page(): void
    {
        $client = $this->unClient();
        $gelee = $this->uneCarte($client);
        $this->actingAs($client)->patch(route('client.wallet.cartes.geler', $gelee->id));

        $this->post(route('client.wallet.recharger'), ['montant' => 5000, 'methode' => 'Carte bancaire', 'carte_id' => $gelee->id])->assertSessionHas('erreur');
        $this->assertSame(0.0, $this->solde($client));
    }

    public function test_une_recharge_par_carte_sans_aucune_carte_est_refusee_puis_mobile_money_passe(): void
    {
        $client = $this->unClient();

        $this->actingAs($client)->post(route('client.wallet.recharger'), ['montant' => 5000, 'methode' => 'Carte bancaire'])->assertSessionHas('erreur');
        $this->assertSame(0.0, $this->solde($client));

        $this->post(route('client.wallet.recharger'), ['montant' => 5000, 'methode' => 'Wave', 'telephone' => '0701020304'])->assertSessionHas('succes')->assertSessionHas('mouvement.type', 'recharge');
        $this->assertSame(5000.0, $this->solde($client));
    }

    public function test_un_retrait_ne_demande_aucune_carte_mais_refuse_une_carte_gelee(): void
    {
        $prestataire = $this->unPrestataire();
        app(WalletService::class)->mouvement($prestataire, 'credit', 20000, 'Gains');
        $this->actingAs($prestataire);

        $this->post(route('prestataire.wallet.retrait'), ['montant' => 5000, 'methode' => 'Wave', 'destination' => '0701020304'])->assertSessionHas('succes')->assertSessionHas('mouvement.type', 'retrait');
        $this->assertSame(15000.0, $this->solde($prestataire));

        $carte = $this->uneCarte($prestataire);
        $this->patch(route('prestataire.wallet.cartes.geler', $carte->id));
        $this->post(route('prestataire.wallet.retrait'), ['montant' => 5000, 'methode' => 'Wave', 'destination' => '0701020304', 'carte_id' => $carte->id])->assertSessionHas('erreur');
        $this->assertSame(15000.0, $this->solde($prestataire));
    }

    public function test_l_historique_s_exporte_en_csv_et_ne_contient_que_mes_operations(): void
    {
        $client = $this->unClient(12345);
        $autre = $this->unClient(99999);

        $reponse = $this->actingAs($client)->get(route('client.wallet.export'))->assertOk();
        $contenu = $reponse->streamedContent();

        $this->assertStringContainsString('text/csv', (string) $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('12345', $contenu);
        $this->assertStringNotContainsString('99999', $contenu);
        $this->assertNotNull($autre);
    }

    public function test_l_export_est_reserve_au_bon_role(): void
    {
        $this->get(route('client.wallet.export'))->assertRedirect(route('connexion'));
        $this->actingAs($this->unPrestataire())->get(route('client.wallet.export'))->assertForbidden();
        $this->actingAs($this->unPrestataire())->get(route('prestataire.wallet.export'))->assertOk();
    }

    public function test_chaque_statut_de_commande_a_sa_couleur(): void
    {
        $this->assertSame(
            ['attente', 'acceptee', 'encours', 'terminee', 'annulee', 'litige'],
            array_map(fn (StatutCommande $s) => $s->nuance(), [StatutCommande::EnAttente, StatutCommande::Acceptee, StatutCommande::EnCours, StatutCommande::Terminee, StatutCommande::Annulee, StatutCommande::Litige]),
        );

        $client = $this->unClient(50000);
        $prestataire = $this->unPrestataire();
        $commande = $this->commander($client, $this->uneOffre($prestataire));
        $this->actingAs($client)->get(route('client.commandes'))->assertOk()->assertSee('statut statut-attente', false);

        app(CommandeService::class)->agir($commande, $prestataire, ActionCommande::Accepter);
        $this->get(route('client.commandes'))->assertSee('statut statut-acceptee', false);

        app(CommandeService::class)->agir($commande, $prestataire, ActionCommande::Demarrer);
        $this->get(route('client.commandes'))->assertSee('statut statut-encours', false);

        app(CommandeService::class)->agir($commande, $prestataire, ActionCommande::Terminer);
        $this->get(route('client.commandes'))->assertSee('statut statut-terminee', false);
    }
}
