<?php

namespace Tests\Feature\Surveillance;

use App\Services\Surveillance\Sentry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SentryTest extends TestCase
{
    private const DSN = 'https://cle123@o1.ingest.sentry.io/456';

    public function test_sans_dsn_rien_n_est_envoye(): void
    {
        Http::fake();
        config(['koudmain.sentry.dsn' => null]);

        $sentry = new Sentry;
        $sentry->capturer(new RuntimeException('boum'));
        $sentry->message('bonjour');

        $this->assertFalse($sentry->actif());
        Http::assertNothingSent();
    }

    public function test_un_dsn_invalide_est_ignore(): void
    {
        Http::fake();
        config(['koudmain.sentry.dsn' => 'pas-un-dsn']);

        (new Sentry)->capturer(new RuntimeException('boum'));

        Http::assertNothingSent();
    }

    public function test_le_dsn_donne_l_adresse_et_la_cle(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN]);

        $cfg = (new Sentry)->configuration();

        $this->assertSame('cle123', $cfg['cle']);
        $this->assertSame('https://o1.ingest.sentry.io/api/456/envelope/', $cfg['url']);
    }

    public function test_une_exception_part_au_format_envelope_avec_la_cle(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN, 'koudmain.sentry.version' => 'koudmain@1.2.3']);
        Http::fake();

        (new Sentry)->capturer(new RuntimeException('boum'));

        Http::assertSent(function ($requete) {
            $lignes = explode("\n", trim($requete->body()));
            $evenement = json_decode($lignes[2], true);

            return $requete->url() === 'https://o1.ingest.sentry.io/api/456/envelope/'
                && str_contains($requete->header('X-Sentry-Auth')[0], 'sentry_key=cle123')
                && $requete->header('Content-Type')[0] === 'application/x-sentry-envelope'
                && $evenement['release'] === 'koudmain@1.2.3'
                && $evenement['exception']['values'][0]['type'] === RuntimeException::class
                && $evenement['exception']['values'][0]['value'] === 'boum';
        });
    }

    public function test_les_donnees_personnelles_sont_retirees_des_messages(): void
    {
        $sentry = new Sentry;

        $texte = $sentry->assainir('Key (email)=(mariam@exemple.ci) already exists. Tel 0712345678, montant 5000');

        $this->assertStringNotContainsString('mariam', $texte);
        $this->assertStringNotContainsString('0712345678', $texte);
        $this->assertStringContainsString('montant 5000', $texte);
    }

    public function test_pas_plus_de_vingt_envois_par_requete(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN]);
        Http::fake();
        $sentry = new Sentry;

        foreach (range(1, 30) as $i) {
            $sentry->message("erreur $i");
        }

        Http::assertSentCount(20);
    }

    public function test_un_sentry_injoignable_ne_casse_rien(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN]);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        (new Sentry)->capturer(new RuntimeException('boum'));

        $this->addToAssertionCount(1);
    }

    public function test_une_erreur_inattendue_de_la_page_est_rapportee(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN]);
        Http::fake();
        \Illuminate\Support\Facades\Route::get('/_test/boum', fn () => throw new RuntimeException('plantage de test'));

        $this->get('/_test/boum')->assertStatus(500);

        Http::assertSent(fn ($requete) => str_contains($requete->body(), 'plantage de test'));
    }

    public function test_une_page_introuvable_n_est_pas_rapportee(): void
    {
        config(['koudmain.sentry.dsn' => self::DSN]);
        Http::fake();

        $this->get('/cette-page-n-existe-pas')->assertNotFound();

        Http::assertNothingSent();
    }
}
