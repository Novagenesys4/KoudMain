<?php

namespace Tests\Feature\Notifications;

use App\Enums\ActionCommande;
use App\Mail\NotificationMail;
use App\Models\Commande;
use App\Models\User;
use App\Services\CommandeService;
use App\Services\ConfirmationEmailService;
use App\Services\Notifications\NotificationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** Les notifications : la cloche, la page, les e-mails, et QUI est prévenu de QUOI à chaque étape d'une commande. */
class NotificationsTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private User $client;

    private User $prestataire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        config(['koudmain.paiement.driver' => 'simulation', 'koudmain.temps_reel.actif' => true, 'koudmain.notifications.email' => true]);
        Mail::fake();

        $this->prestataire = $this->unPrestataire();
        $this->client = $this->unClient(80000);
    }

    private function agir(Commande $commande, User $acteur, ActionCommande $action): void
    {
        app(CommandeService::class)->agir($commande->fresh(), $acteur, $action, $action === ActionCommande::OuvrirLitige ? 'Le travail est bâclé' : null);
    }

    /** @return list<string> les titres des notifications de la personne, de la plus ancienne à la plus récente */
    private function titres(User $u): array
    {
        return $u->notifications()->oldest()->get()->map(fn ($n) => $n->data['titre'])->all();
    }

    private function evenementsDe(User $u, string $type): int
    {
        return DB::table('evenements_temps_reel')->where('user_id', $u->id)->where('type', $type)->count();
    }

    // ------------------------------------------------------ Cycle d'une commande

    public function test_une_commande_previent_le_prestataire_par_cloche_et_e_mail(): void
    {
        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire));

        $this->assertSame(['Nouvelle commande'], $this->titres($this->prestataire));
        $this->assertSame([], $this->titres($this->client));
        $this->assertSame(1, $this->evenementsDe($this->prestataire, 'notification'));
        $this->assertSame(1, $this->evenementsDe($this->prestataire, 'commande')); // et sa page se rafraîchit
        $this->assertSame(1, $this->evenementsDe($this->client, 'commande'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($this->prestataire->email) && str_contains($m->lien, '/prestataire/commandes/'.$commande->id));
    }

    public function test_le_parcours_complet_previent_la_bonne_personne_a_chaque_etape(): void
    {
        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire, 10000));

        $this->agir($commande, $this->prestataire, ActionCommande::Accepter);
        $this->assertSame(['Commande acceptée'], $this->titres($this->client));

        $this->agir($commande, $this->prestataire, ActionCommande::Demarrer);
        $this->agir($commande, $this->prestataire, ActionCommande::Terminer);
        $this->assertSame(['Commande acceptée', 'Prestation démarrée', 'Prestation terminée'], $this->titres($this->client));

        $this->agir($commande, $this->client, ActionCommande::ConfirmerReception);

        // Le prestataire est payé ; le client est invité à donner sa note.
        $this->assertSame(['Nouvelle commande', 'Paiement reçu'], $this->titres($this->prestataire));
        $this->assertSame(['Commande acceptée', 'Prestation démarrée', 'Prestation terminée', 'Comment était la prestation ?'], $this->titres($this->client));
    }

    public function test_annuler_previent_l_autre_partie(): void
    {
        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire));

        $this->agir($commande, $this->client, ActionCommande::Annuler);
        $this->assertContains('Commande annulée', $this->titres($this->prestataire));
        $this->assertNotContains('Commande annulée', $this->titres($this->client)); // on ne prévient pas celui qui vient d'agir

        $autre = $this->commander($this->client, $this->uneOffre($this->prestataire), 1, $this->demain('15:00'));
        $this->agir($autre, $this->prestataire, ActionCommande::Annuler);
        $this->assertContains('Commande annulée', $this->titres($this->client));
    }

    public function test_un_litige_previent_le_prestataire_et_fait_rafraichir_les_pages_des_administrateurs(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire));
        $this->agir($commande, $this->prestataire, ActionCommande::Accepter);
        $this->agir($commande, $this->prestataire, ActionCommande::Demarrer);
        $this->agir($commande, $this->prestataire, ActionCommande::Terminer);
        DB::table('evenements_temps_reel')->delete();

        $this->agir($commande, $this->client, ActionCommande::OuvrirLitige);

        $this->assertContains('Problème signalé', $this->titres($this->prestataire));
        $this->assertSame(1, $this->evenementsDe($admin, 'commande'));
    }

    public function test_l_arbitrage_previent_les_deux_parties(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire));
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $a) {
            $this->agir($commande, $this->prestataire, $a);
        }
        $this->agir($commande, $this->client, ActionCommande::OuvrirLitige);

        app(CommandeService::class)->arbitrer($commande->fresh(), $admin, false, 'Remboursement justifié');

        $this->assertContains('Litige tranché en votre faveur', $this->titres($this->client));
        $this->assertContains('Litige tranché', $this->titres($this->prestataire));
    }

    public function test_un_retrait_previent_les_admins_puis_le_prestataire(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        app(WalletService::class)->mouvement($this->prestataire, 'credit', 20000, 'Départ');
        DB::table('evenements_temps_reel')->delete();

        $retrait = app(WalletService::class)->demanderRetrait($this->prestataire, 5000, 'Wave', '0701020304');
        $this->assertSame(1, $this->evenementsDe($admin, 'retrait'));

        app(WalletService::class)->confirmerRetrait($admin, $retrait);
        $this->assertContains('Retrait effectué', $this->titres($this->prestataire));

        $autre = app(WalletService::class)->demanderRetrait($this->prestataire, 5000, 'Wave', '0701020304');
        app(WalletService::class)->refuserRetrait($admin, $autre, 'Numéro invalide');
        $this->assertContains('Retrait refusé', $this->titres($this->prestataire));
    }

    // ----------------------------------------------------------------- E-mails

    public function test_les_e_mails_respectent_le_choix_de_la_personne_et_le_reglage_global(): void
    {
        $this->prestataire->forceFill(['notifications_email' => false])->save();
        $this->commander($this->client, $this->uneOffre($this->prestataire));
        $this->assertSame(['Nouvelle commande'], $this->titres($this->prestataire)); // la cloche marche toujours
        Mail::assertNothingSent();

        $this->prestataire->forceFill(['notifications_email' => true])->save();
        config(['koudmain.notifications.email' => false]);
        $this->commander($this->client, $this->uneOffre($this->prestataire), 1, $this->demain('15:00'));
        Mail::assertNothingSent();
    }

    public function test_une_panne_d_e_mail_ne_fait_jamais_echouer_l_action(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP hors service'));

        $commande = $this->commander($this->client, $this->uneOffre($this->prestataire));

        $this->assertNotNull($commande->id);
        $this->assertSame(['Nouvelle commande'], $this->titres($this->prestataire));
    }

    public function test_le_mail_est_lisible_et_donne_le_lien(): void
    {
        $mail = new NotificationMail('Mariam', 'Nouvelle commande', 'Awa a commandé « Tresses ».', 'https://koudmain.test/prestataire/commandes/4', 'Voir la commande');

        $mail->assertSeeInHtml('Bonjour Mariam');
        $mail->assertSeeInHtml('Awa a commandé');
        $mail->assertSeeInHtml('https://koudmain.test/prestataire/commandes/4');
        $mail->assertSeeInText('https://koudmain.test/prestataire/commandes/4');
        $mail->assertHasSubject('Nouvelle commande · KoudMain');
    }

    // --------------------------------------------------- Inscription et profil

    public function test_l_inscription_et_la_validation_d_un_prestataire_previennent(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        $quartier = $this->creerQuartier();

        $this->post('/inscription', [
            'role' => 'prestataire', 'prenom' => 'Kader', 'nom' => 'Sylla', 'email' => 'kader@exemple.ci', 'telephone' => '0707070707',
            'quartier_id' => $quartier->id, 'password' => 'Motdepasse1', 'password_confirmation' => 'Motdepasse1',
        ])->assertSessionHasNoErrors();

        $kader = User::query()->where('email', 'kader@exemple.ci')->firstOrFail();

        // Règle 19 : tant que l'adresse n'est pas confirmée, ni bienvenue ni alerte aux administrateurs (une adresse fictive ne
        // doit pas remplir la file de validation).
        $this->assertSame([], $this->titres($kader));
        $this->assertSame(0, $this->evenementsDe($admin, 'admin'));

        $this->assertTrue(app(ConfirmationEmailService::class)->confirmer($kader));
        $this->assertSame(['Bienvenue sur KoudMain'], $this->titres($kader));
        $this->assertSame(1, $this->evenementsDe($admin, 'admin')); // la page « Prestataires » de l'admin se rafraîchit

        $this->actingAs($admin)->post(route('admin.prestataires.valider', $kader))->assertSessionHas('succes');
        $this->assertContains('Profil validé', $this->titres($kader));

        $this->post(route('admin.prestataires.suspendre', $kader));
        $this->assertContains('Profil suspendu', $this->titres($kader));
    }

    public function test_le_profil_permet_de_couper_les_e_mails(): void
    {
        $this->actingAs($this->client)->get(route('compte.profil'))->assertOk()->assertSee('Recevoir des e-mails');

        $this->actingAs($this->client)->put(route('compte.notifications'), ['notifications_email' => '0'])->assertSessionHas('succes');
        $this->assertFalse($this->client->fresh()->notifications_email);

        $this->put(route('compte.notifications'), ['notifications_email' => '1']);
        $this->assertTrue($this->client->fresh()->notifications_email);

        $this->put(route('compte.notifications'), [])->assertSessionHasErrors('notifications_email');
    }

    // ----------------------------------------------------------- Page et cloche

    public function test_la_page_liste_filtre_et_compte_les_non_lues(): void
    {
        $service = app(NotificationService::class);
        $service->envoyer($this->client, 'test', 'Première', 'Texte un', '/client', 'coche');
        $this->client->unreadNotifications()->firstOrFail()->markAsRead(); // avant la seconde : l'ordre ne dépend plus de l'horloge
        $service->envoyer($this->client, 'test', 'Seconde', 'Texte deux', '/client/wallet', 'portefeuille');

        $this->actingAs($this->client)->get(route('notifications'))->assertOk()->assertSee('Première')->assertSee('Seconde');
        $this->get(route('notifications', ['filtre' => 'non-lues']))->assertOk()->assertSee('Seconde')->assertDontSee('Première');
    }

    public function test_ouvrir_une_notification_la_marque_lue_et_va_a_la_page_concernee(): void
    {
        $n = app(NotificationService::class)->envoyer($this->client, 'test', 'Bonjour', 'Texte', '/client/wallet', 'coche');

        $this->actingAs($this->client)->post(route('notifications.lire', $n->id))->assertRedirect('/client/wallet');

        $this->assertNotNull($n->fresh()->read_at);
        $this->assertSame(1, $this->evenementsDe($this->client, 'notifications_lues')); // les autres onglets baissent leur pastille
    }

    public function test_une_adresse_externe_enregistree_ne_redirige_jamais_hors_du_site(): void
    {
        $n = app(NotificationService::class)->envoyer($this->client, 'test', 'Piège', 'Texte', '//pirate.example/vol', 'coche');

        $this->actingAs($this->client)->post(route('notifications.lire', $n->id))->assertRedirect('/');
    }

    public function test_on_ne_lit_pas_les_notifications_des_autres(): void
    {
        $n = app(NotificationService::class)->envoyer($this->client, 'test', 'Privé', 'Texte', '/client', 'coche');

        $this->actingAs($this->prestataire)->post(route('notifications.lire', $n->id))->assertNotFound();
        $this->assertNull($n->fresh()->read_at);
        $this->actingAs($this->prestataire)->get(route('notifications'))->assertDontSee('Privé');
        $this->actingAs($this->prestataire)->getJson(route('notifications.recentes'))->assertJsonCount(0, 'notifications');
    }

    public function test_tout_marquer_comme_lu_et_la_liste_courte_de_la_cloche(): void
    {
        $service = app(NotificationService::class);
        foreach (['A', 'B', 'C'] as $t) {
            $service->envoyer($this->client, 'test', $t, 'Texte', '/client', 'coche');
            \Illuminate\Support\Carbon::setTestNow(now()->addMinute()); // la plus récente en premier
        }

        $this->actingAs($this->client)->getJson(route('notifications.recentes'))->assertJsonPath('non_lues', 3)->assertJsonCount(3, 'notifications')->assertJsonPath('notifications.0.titre', 'C');

        $this->postJson(route('notifications.tout-lire'))->assertJsonPath('non_lues', 0);
        $this->assertSame(0, $service->nonLues($this->client));
    }

    public function test_la_cloche_de_l_en_tete_affiche_le_nombre_de_non_lues(): void
    {
        app(NotificationService::class)->envoyer($this->client, 'test', 'A', 'Texte', '/client', 'coche');

        $this->actingAs($this->client)->get(route('client.tableau-de-bord'))->assertOk()
            ->assertSee('data-non-lues-notifications="1"', false)->assertSee('data-island="Cloche"', false);
    }
}
