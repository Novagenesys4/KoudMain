<?php

namespace Tests\Feature\Messagerie;

use App\Exceptions\OperationRefusee;
use App\Mail\NotificationMail;
use App\Models\Commande;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/** La messagerie : une discussion par commande, entre son client et son prestataire. */
class MessagerieTest extends TestCase
{
    use CreeDesCommandes;
    use RefreshDatabase;

    private User $client;

    private User $prestataire;

    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->figerLeTemps();
        config(['koudmain.paiement.driver' => 'simulation', 'koudmain.temps_reel.actif' => true]);
        Mail::fake();

        $this->prestataire = $this->unPrestataire();
        $this->client = $this->unClient(50000);
        $this->commande = $this->commander($this->client, $this->uneOffre($this->prestataire));
        DB::table('evenements_temps_reel')->delete();
        Mail::fake(); // on repart de zéro : l'e-mail « nouvelle commande » de la mise en place ne compte pas
    }

    private function service(): MessageService
    {
        return app(MessageService::class);
    }

    private function evenements(User $u, string $type): array
    {
        return DB::table('evenements_temps_reel')->where('user_id', $u->id)->where('type', $type)->orderBy('id')->get()->map(fn ($e) => json_decode($e->donnees, true))->all();
    }

    // ------------------------------------------------------------- Envoi

    public function test_le_client_ecrit_et_le_prestataire_recoit_en_direct(): void
    {
        $message = $this->service()->envoyer($this->commande, $this->client, "  Bonjour !\r\n\r\n\r\n\r\nÀ samedi  ");

        $this->assertSame("Bonjour !\n\nÀ samedi", $message->contenu); // nettoyé : espaces, fins de ligne, lignes vides
        $this->assertFalse($message->lu);

        $recu = $this->evenements($this->prestataire, 'message');
        $this->assertCount(1, $recu);
        $this->assertSame($this->commande->id, $recu[0]['commande_id']);
        $this->assertSame($message->id, $recu[0]['message']['id']);
        $this->assertSame(1, $recu[0]['non_lus_total']);
        $this->assertSame($this->client->prenom, $recu[0]['de']);

        // L'expéditeur reçoit aussi l'événement (ses autres onglets) mais n'a rien de non lu.
        $this->assertSame(0, $this->evenements($this->client, 'message')[0]['non_lus_total']);
    }

    public function test_la_conversation_se_cree_une_seule_fois(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Un');
        $this->service()->envoyer($this->commande, $this->prestataire, 'Deux');
        $this->service()->envoyer($this->commande, $this->client, 'Trois');

        $this->assertSame(1, DB::table('conversations')->where('commande_id', $this->commande->id)->count());
        $this->assertSame(3, Message::query()->count());
    }

    public function test_un_etranger_ne_peut_pas_ecrire(): void
    {
        $intrus = $this->unClient();

        $this->expectException(OperationRefusee::class);
        $this->service()->envoyer($this->commande, $intrus, 'Coucou');
    }

    public function test_un_message_vide_ou_trop_long_est_refuse(): void
    {
        foreach (['', "   \n  ", str_repeat('a', 2001)] as $contenu) {
            try {
                $this->service()->envoyer($this->commande, $this->client, $contenu);
                $this->fail('Le message aurait dû être refusé.');
            } catch (OperationRefusee) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Message::query()->count());
        $this->assertSame(2000, mb_strlen($this->service()->envoyer($this->commande, $this->client, str_repeat('é', 2000))->contenu)); // 2000 caractères, pas 2000 octets
    }

    public function test_le_contenu_est_affiche_echappe(): void
    {
        $this->service()->envoyer($this->commande, $this->client, '<script>alert(1)</script>');

        $this->actingAs($this->prestataire)->get(route('messages.voir', $this->commande))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // --------------------------------------------------------------- Lu

    public function test_ouvrir_la_discussion_marque_lu_et_previent_l_expediteur(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Bonjour');
        $this->assertSame(1, $this->service()->nonLus($this->prestataire));
        $this->assertSame(0, $this->service()->nonLus($this->client));

        $this->actingAs($this->prestataire)->get(route('messages.voir', $this->commande))->assertOk();

        $this->assertSame(0, $this->service()->nonLus($this->prestataire));
        $this->assertTrue(Message::query()->firstOrFail()->lu);
        $this->assertCount(1, $this->evenements($this->client, 'messages_lus')); // les ✓✓ du client
        $this->assertSame(0, $this->evenements($this->prestataire, 'non_lus')[0]['non_lus_total']);
    }

    public function test_un_rafraichissement_en_arriere_plan_ne_compte_pas_comme_une_lecture(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Bonjour');

        $this->actingAs($this->prestataire)->get(route('messages.voir', $this->commande), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->assertSame(1, $this->service()->nonLus($this->prestataire));
    }

    public function test_ses_propres_messages_ne_sont_jamais_non_lus_pour_soi(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Bonjour');

        $this->assertSame(0, $this->service()->marquerLus($this->commande, $this->client));
        $this->assertFalse(Message::query()->firstOrFail()->lu);
    }

    public function test_la_route_lu_marque_les_messages_recus(): void
    {
        $this->service()->envoyer($this->commande, $this->prestataire, 'Je suis là');

        $this->actingAs($this->client)->postJson(route('messages.lu', $this->commande))->assertNoContent();

        $this->assertSame(0, $this->service()->nonLus($this->client));
    }

    // -------------------------------------------------------- Écrit / e-mail

    public function test_le_signal_en_train_d_ecrire_va_a_l_autre_personne(): void
    {
        $this->actingAs($this->client)->postJson(route('messages.ecrit', $this->commande))->assertNoContent();

        $this->assertCount(1, $this->evenements($this->prestataire, 'ecrit'));
        $this->assertCount(0, $this->evenements($this->client, 'ecrit'));
        $this->assertSame($this->client->id, $this->evenements($this->prestataire, 'ecrit')[0]['utilisateur_id']);
    }

    public function test_un_e_mail_previent_le_destinataire_absent_une_fois_par_serie(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Premier');
        $this->service()->envoyer($this->commande, $this->client, 'Deuxième');
        $this->service()->envoyer($this->commande, $this->client, 'Troisième');

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($this->prestataire->email));
    }

    public function test_pas_d_e_mail_si_le_destinataire_est_connecte_ou_les_refuse(): void
    {
        app(Diffuseur::class)->marquerPresent($this->prestataire->id);
        $this->service()->envoyer($this->commande, $this->client, 'Vous êtes là ?');
        Mail::assertNothingSent();

        $this->client->forceFill(['notifications_email' => false])->save();
        $this->service()->envoyer($this->commande, $this->prestataire, 'Oui'); // série de l'autre côté : le client refuse les e-mails
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------- Pages

    public function test_la_page_liste_les_discussions_et_ouvre_celle_de_la_commande(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Bonjour Mariam');

        $this->actingAs($this->prestataire)->get(route('messages'))->assertOk()->assertSee('Bonjour Mariam')->assertSee('Choisissez une discussion');
        $this->actingAs($this->prestataire)->get(route('messages.voir', $this->commande))->assertOk()
            ->assertSee('data-island="Messagerie"', false)->assertSee('name="contenu"', false);
    }

    public function test_une_commande_sans_discussion_n_est_listee_que_si_elle_est_en_cours(): void
    {
        $terminee = $this->commander($this->client, $this->uneOffre($this->prestataire), 1, $this->demain('14:00'));
        $terminee->forceFill(['statut' => 'terminee'])->save();

        $ids = collect($this->service()->echanges($this->client))->pluck('commande_id')->all();

        $this->assertContains($this->commande->id, $ids);      // en attente : on peut écrire en premier
        $this->assertNotContains($terminee->id, $ids);         // terminée et muette : pas de bruit

        $this->service()->envoyer($terminee, $this->client, 'Merci !');
        $this->assertContains($terminee->id, collect($this->service()->echanges($this->client))->pluck('commande_id')->all());
    }

    public function test_la_liste_range_la_discussion_la_plus_recente_en_premier_avec_ses_non_lus(): void
    {
        $autre = $this->commander($this->client, $this->uneOffre($this->prestataire), 1, $this->demain('14:00'));
        $this->service()->envoyer($this->commande, $this->prestataire, 'Ancien');
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(5));
        $this->service()->envoyer($autre, $this->prestataire, 'Récent');

        $echanges = $this->service()->echanges($this->client);

        $this->assertSame($autre->id, $echanges[0]['commande_id']);
        $this->assertSame(1, $echanges[0]['non_lus']);
        $this->assertSame('Récent', $echanges[0]['apercu']);
        $this->assertFalse($echanges[0]['apercu_moi']);
    }

    public function test_le_fil_se_charge_par_tranches(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->service()->envoyer($this->commande, $i % 2 ? $this->client : $this->prestataire, "Message $i");
        }
        config(['koudmain.messagerie.par_page' => 3]);

        $dernier = $this->actingAs($this->client)->getJson(route('messages.fil', $this->commande))->assertOk();
        $dernier->assertJsonCount(3, 'messages')->assertJsonPath('plus_anciens', true)->assertJsonPath('messages.2.contenu', 'Message 5');

        $avant = $dernier->json('messages.0.id');
        $this->actingAs($this->client)->getJson(route('messages.fil', ['commande' => $this->commande, 'avant' => $avant]))
            ->assertJsonCount(2, 'messages')->assertJsonPath('plus_anciens', false)->assertJsonPath('messages.0.contenu', 'Message 1');
    }

    // ---------------------------------------------------------- Sécurité

    public function test_une_commande_qui_n_est_pas_la_votre_est_introuvable_partout(): void
    {
        $intrus = $this->unClient();
        $this->service()->envoyer($this->commande, $this->client, 'Secret');

        $this->actingAs($intrus)->get(route('messages.voir', $this->commande))->assertNotFound();
        $this->actingAs($intrus)->getJson(route('messages.fil', $this->commande))->assertNotFound();
        $this->actingAs($intrus)->postJson(route('messages.envoyer', $this->commande), ['contenu' => 'Coucou'])->assertNotFound();
        $this->actingAs($intrus)->postJson(route('messages.lu', $this->commande))->assertNotFound();
        $this->actingAs($intrus)->postJson(route('messages.ecrit', $this->commande))->assertNotFound();

        $this->assertSame(1, Message::query()->count());
        $this->assertFalse(Message::query()->firstOrFail()->lu);
    }

    public function test_un_administrateur_n_a_pas_de_messagerie_mais_lit_l_echange_d_un_litige(): void
    {
        $admin = User::factory()->admin()->create(['quartier_id' => $this->creerQuartier()->id]);
        $this->service()->envoyer($this->commande, $this->client, 'Le travail est mal fait');

        $this->actingAs($admin)->get(route('messages'))->assertNotFound();
        $this->actingAs($admin)->postJson(route('messages.envoyer', $this->commande), ['contenu' => 'Intrusion'])->assertNotFound();

        $this->actingAs($admin)->get(route('admin.commandes.voir', $this->commande))->assertOk()
            ->assertSee('Échanges entre les parties')->assertSee('Le travail est mal fait');
        $this->assertSame(1, Message::query()->count());
    }

    public function test_l_envoi_par_formulaire_sans_javascript_revient_sur_la_discussion(): void
    {
        $this->actingAs($this->client)->post(route('messages.envoyer', $this->commande), ['contenu' => 'Sans JS'])->assertRedirect(route('messages.voir', $this->commande));
        $this->assertSame('Sans JS', Message::query()->firstOrFail()->contenu);

        // Vide : la validation du formulaire le refuse ; trop long : c'est la règle du service.
        $this->actingAs($this->client)->from(route('messages.voir', $this->commande))->post(route('messages.envoyer', $this->commande), ['contenu' => '   '])
            ->assertRedirect(route('messages.voir', $this->commande))->assertSessionHasErrors('contenu');
        $this->actingAs($this->client)->from(route('messages.voir', $this->commande))->post(route('messages.envoyer', $this->commande), ['contenu' => str_repeat('a', 2001)])
            ->assertRedirect(route('messages.voir', $this->commande))->assertSessionHas('erreur');
        $this->assertSame(1, Message::query()->count());
    }

    public function test_la_reponse_json_d_erreur_dit_pourquoi(): void
    {
        $this->actingAs($this->client)->postJson(route('messages.envoyer', $this->commande), ['contenu' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonPath('message', 'Un message ne peut pas dépasser 2000 caractères.');
    }

    public function test_les_pastilles_de_messages_sont_dans_la_page(): void
    {
        $this->service()->envoyer($this->commande, $this->client, 'Salut');

        $this->actingAs($this->prestataire)->get(route('prestataire.tableau-de-bord'))->assertOk()
            ->assertSee('data-non-lus-messages="1"', false)->assertSee('data-badge="messages"', false);
    }
}
