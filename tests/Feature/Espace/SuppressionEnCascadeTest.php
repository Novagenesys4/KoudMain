<?php

namespace Tests\Feature\Espace;

use App\Models\Commande;
use App\Models\Escrow;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/**
 * Supprimer un compte emporte tout ce qui lui appartient (commandes, séquestres, wallet, paiements, retraits…),
 * sans jamais faire perdre d'argent à l'AUTRE partie d'une commande.
 */
class SuppressionEnCascadeTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        $this->admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function supprimer(User $cible): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->delete(route('admin.utilisateurs.supprimer', $cible->id));
    }

    private function unPaiementEtUnRetrait(User $utilisateur): void
    {
        DB::table('paiements')->insert(['user_id' => $utilisateur->id, 'reference' => 'REF-'.$utilisateur->id, 'fournisseur' => 'simulation', 'methode' => 'Orange Money', 'montant' => 5000, 'statut' => 'reussi', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('retraits')->insert(['user_id' => $utilisateur->id, 'montant' => 2000, 'methode' => 'Wave', 'destination' => '0700000000', 'statut' => 'en_attente', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_un_client_avec_commandes_argent_paiements_et_retraits_est_supprime_en_cascade(): void
    {
        $offre = $this->uneOffre(prix: 5000);
        $prestataire = $offre->prestataire;
        $client = $this->unClient(20000);
        $this->commander($client, $offre);
        $this->unPaiementEtUnRetrait($client);
        $client->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'data' => ['titre' => 'x']]);

        $this->supprimer($client)->assertSessionHas('succes')->assertSessionMissing('erreur');

        $this->assertModelMissing($client);
        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(0, Escrow::query()->count());
        foreach (['wallets', 'wallet_transactions', 'paiements', 'retraits', 'conversations'] as $table) {
            $this->assertSame(0, DB::table($table)->where($table === 'conversations' ? 'commande_id' : ($table === 'wallet_transactions' ? 'wallet_id' : 'user_id'), '>', 0)->count(), "table $table non vidée");
        }
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $client->id)->count());

        // L'autre partie et son offre ne bougent pas ; la prestataire est prévenue de la commande annulée.
        $this->assertModelExists($prestataire);
        $this->assertModelExists($offre);
        $this->assertSame(1, $prestataire->notifications()->where('type', 'commande_annulee')->count());
    }

    public function test_supprimer_un_prestataire_rembourse_le_client_dont_l_argent_est_bloque(): void
    {
        $offre = $this->uneOffre(prix: 5000);
        $prestataire = $offre->prestataire;
        $client = $this->unClient(12000);
        $commande = $this->commander($client, $offre);
        $this->assertSame(7000.0, $this->solde($client));

        $this->supprimer($prestataire)->assertSessionHas('succes');

        $this->assertModelMissing($prestataire);
        $this->assertModelMissing($offre);
        $this->assertModelMissing($commande);
        $this->assertSame(12000.0, $this->solde($client), 'le client retrouve son argent');
        $this->assertModelExists($client);

        $ligne = DB::table('wallet_transactions')->where('libelle', 'like', 'Remboursement%')->first();
        $this->assertNotNull($ligne, 'la trace du remboursement reste dans l\'historique du client');
        $this->assertNull($ligne->commande_id);

        $note = $client->notifications()->where('type', 'commande_annulee')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('compte', $note->data['texte']);
        $this->assertStringContainsString('remis sur votre wallet', $note->data['texte']);
    }

    public function test_un_sequestre_en_litige_est_aussi_rendu_au_client(): void
    {
        $offre = $this->uneOffre(prix: 4000);
        $client = $this->unClient(4000);
        $commande = $this->commander($client, $offre);
        Escrow::query()->where('commande_id', $commande->id)->update(['statut' => Escrow::LITIGE]);
        $commande->forceFill(['statut' => 'litige'])->save();

        $this->supprimer($offre->prestataire)->assertSessionHas('succes');

        $this->assertSame(4000.0, $this->solde($client));
    }

    public function test_un_sequestre_deja_libere_ne_donne_pas_de_second_remboursement(): void
    {
        $offre = $this->uneOffre(prix: 3000);
        $client = $this->unClient(10000);
        $commande = $this->commander($client, $offre);
        Escrow::query()->where('commande_id', $commande->id)->update(['statut' => Escrow::LIBERE, 'libere_at' => now()]);
        $commande->forceFill(['statut' => 'terminee'])->save();
        $avant = $this->solde($client);

        $this->supprimer($offre->prestataire)->assertSessionHas('succes');

        $this->assertSame($avant, $this->solde($client));
        $this->assertSame(0, $client->notifications()->where('type', 'commande_annulee')->count(), 'commande terminée : rien à annoncer');
    }

    public function test_un_travail_termine_mais_non_confirme_est_paye_au_prestataire_quand_le_client_est_supprime(): void
    {
        $offre = $this->uneOffre(prix: 6000);
        $prestataire = $offre->prestataire;
        $client = $this->unClient(6000);
        $commande = $this->commander($client, $offre);
        $commande->forceFill(['statut' => 'terminee', 'terminee_at' => now()])->save();

        $this->supprimer($client)->assertSessionHas('succes');

        $this->assertSame(6000.0, $this->solde($prestataire), 'la prestation faite est payée, comme à la libération automatique');
        $note = $prestataire->notifications()->where('type', 'commande_annulee')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('versés sur votre wallet', $note->data['texte']);
    }

    public function test_une_commande_non_terminee_d_un_client_supprime_ne_paie_pas_le_prestataire(): void
    {
        $offre = $this->uneOffre(prix: 6000);
        $client = $this->unClient(6000);
        $this->commander($client, $offre);   // en attente : le travail n'est pas fait

        $this->supprimer($client)->assertSessionHas('succes');

        $this->assertSame(0.0, $this->solde($offre->prestataire));
    }

    public function test_supprimer_un_client_ne_touche_pas_au_wallet_du_prestataire(): void
    {
        $offre = $this->uneOffre(prix: 3000);
        $prestataire = $offre->prestataire;
        app(\App\Services\WalletService::class)->mouvement($prestataire, 'credit', 8000, 'Gains précédents');
        $client = $this->unClient(9000);
        $this->commander($client, $offre);

        $this->supprimer($client)->assertSessionHas('succes');

        $this->assertSame(8000.0, $this->solde($prestataire));
        $this->assertSame(1, DB::table('wallet_transactions')->where('libelle', 'Gains précédents')->count());
    }

    public function test_le_journal_garde_les_montants_effaces(): void
    {
        Log::spy();
        $client = $this->unClient(6500);

        $this->supprimer($client)->assertSessionHas('succes');

        Log::shouldHaveReceived('log')->withArgs(fn ($niveau, $message, $contexte = []) => $message === 'admin.compte_supprime'
            && $contexte['compte'] === $client->id && $contexte['solde_efface'] === 6500.0 && $contexte['commandes_supprimees'] === 0)->once();
    }

    public function test_les_sessions_du_compte_supprime_disparaissent(): void
    {
        $client = $this->unClient();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $client->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->supprimer($client)->assertSessionHas('succes');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $client->id)->count());
    }

    public function test_les_protections_restantes_admin_et_soi_meme(): void
    {
        $autre = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);

        $this->supprimer($autre)->assertSessionHas('erreur');
        $this->supprimer($this->admin)->assertSessionHas('erreur');
        $this->assertModelExists($autre);
    }

    // ------------------------------------------------------------ Au niveau de la base

    public function test_la_base_supprime_en_cascade_meme_sans_passer_par_le_service(): void
    {
        $offre = $this->uneOffre(prix: 3000);
        $client = $this->unClient(5000);
        $this->commander($client, $offre);
        $this->unPaiementEtUnRetrait($client);

        DB::table('users')->where('id', $offre->prestataire_id)->delete();   // ordre de cascade quelconque : doit passer
        DB::table('users')->where('id', $client->id)->delete();

        $this->assertSame(0, Commande::query()->count());
        $this->assertSame(0, DB::table('paiements')->count());
        $this->assertSame(0, DB::table('retraits')->count());
        $this->assertSame(0, DB::table('commande_prestation')->count());
    }

    public function test_une_prestation_deja_commandee_ne_se_supprime_toujours_pas_seule(): void
    {
        $offre = $this->uneOffre(prix: 3000);
        $this->commander($this->unClient(5000), $offre);

        DB::table('prestations')->where('id', $offre->id)->delete();

        // Le contrôle est différé à la fin de la transaction (voir la migration) : on le déclenche ici.
        $this->expectException(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_les_prestations_et_leurs_photos_partent_avec_un_prestataire_qui_a_des_commandes(): void
    {
        $offre = $this->uneOffre(prix: 3000);
        $photo = $this->unePhoto($offre);
        $this->commander($this->unClient(5000), $offre);

        $this->supprimer($offre->prestataire)->assertSessionHas('succes');

        $this->assertModelMissing($offre);
        $this->assertModelMissing($photo);
        $this->assertSame(0, Prestation::query()->count());
    }
}
