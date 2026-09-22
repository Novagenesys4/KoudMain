<?php

namespace Tests\Feature\Surveillance;

use App\Support\Journal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Le journal des actions : lisible, jamais bavard sur les secrets, jamais bloquant. */
class JournalTest extends TestCase
{
    public function test_les_secrets_sont_masques(): void
    {
        $propre = Journal::nettoyer([
            'password' => 'motdepasse123',
            'token' => 'abc',
            'cvv' => '123',
            'api_key' => 'sk_live_xxx',
            'commande' => 42,
        ]);

        $this->assertSame('[masqué]', $propre['password']);
        $this->assertSame('[masqué]', $propre['token']);
        $this->assertSame('[masqué]', $propre['cvv']);
        $this->assertSame('[masqué]', $propre['api_key']);
        $this->assertSame(42, $propre['commande']);
    }

    public function test_l_adresse_e_mail_et_les_numeros_sont_reduits(): void
    {
        $propre = Journal::nettoyer(['email' => 'mariam@exemple.ci', 'destination' => '07 12 34 56 78', 'telephone' => '12']);

        $this->assertSame('m***@exemple.ci', $propre['email']);
        $this->assertSame('***5678', $propre['destination']);
        $this->assertSame('****', $propre['telephone']);
    }

    public function test_le_masquage_descend_dans_les_tableaux_et_borne_les_textes(): void
    {
        $propre = Journal::nettoyer(['detail' => ['secret' => 'x', 'texte' => str_repeat('a', 900)], 'objet' => new \stdClass]);

        $this->assertSame('[masqué]', $propre['detail']['secret']);
        $this->assertSame(500, mb_strlen($propre['detail']['texte']));
        $this->assertSame('[stdClass]', $propre['objet']);
    }

    public function test_une_ligne_est_ecrite_avec_son_contexte_masque(): void
    {
        Log::spy();

        Journal::info('connexion.echec', ['email' => 'a@b.ci', 'ip' => '10.0.0.1']);

        Log::shouldHaveReceived('log')->once()->with('info', 'connexion.echec', ['email' => 'a***@b.ci', 'ip' => '10.0.0.1']);
    }

    public function test_une_erreur_est_aussi_envoyee_a_sentry(): void
    {
        config(['koudmain.sentry.dsn' => 'https://cle123@o1.ingest.sentry.io/456']);
        Http::fake();

        Journal::erreur('paiement.montant_different', ['paiement' => 7, 'password' => 'x']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($requete) => str_contains($requete->body(), 'paiement.montant_different') && ! str_contains($requete->body(), '"x"'));
    }

    public function test_une_erreur_avec_cause_envoie_l_exception_et_note_le_fichier(): void
    {
        config(['koudmain.sentry.dsn' => 'https://cle123@o1.ingest.sentry.io/456']);
        Http::fake();
        Log::spy();

        Journal::erreur('tache.echec', ['tache' => 'nettoyage'], new RuntimeException('boum'));

        Log::shouldHaveReceived('log')->once()->with('error', 'tache.echec', Mockery::on(
            fn ($c) => $c['exception'] === RuntimeException::class && $c['message'] === 'boum' && str_contains($c['fichier'], 'JournalTest.php'),
        ));
        Http::assertSent(fn ($requete) => str_contains($requete->body(), 'RuntimeException') && str_contains($requete->body(), 'boum'));
    }

    public function test_un_journal_en_panne_ne_casse_rien(): void
    {
        Log::shouldReceive('log')->andThrow(new RuntimeException('disque plein'));

        Journal::info('commande.passee', ['commande' => 1]);
        Journal::erreur('quelque.chose');

        $this->addToAssertionCount(1);
    }
}
