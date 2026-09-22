<?php

namespace Tests\Feature\Securite;

use App\Support\ControleProduction;
use Tests\TestCase;

/** Règles 1, 2, 5, 7, 8, 14, 15, 17, 19 : la configuration de production est contrôlée avant le démarrage. */
class ControleProductionTest extends TestCase
{
    /** Une configuration de production correcte : aucune erreur attendue. @return array<string, string> */
    private function envSain(array $surcharge = []): array
    {
        return $surcharge + [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_URL' => 'https://koudmain.onrender.com', 'TRUST_PROXY' => 'true', 'SESSION_SECURE_COOKIE' => 'true',
            'DB_HOST' => 'aws-0.pooler.supabase.com', 'DB_PASSWORD' => 'Xk9-vraiment-long-et-aleatoire-4711', 'DB_SSLMODE' => 'require',
            'MAIL_MAILER' => 'smtp', 'PAIEMENT_DRIVER' => 'aucun', 'LOG_LEVEL' => 'info', 'BCRYPT_ROUNDS' => '12',
        ];
    }

    /** @return list<string> les messages d'un niveau donné */
    private function messages(array $env, string $niveau = 'erreur'): array
    {
        return array_column(array_filter(ControleProduction::verifier($env, sys_get_temp_dir().'/sans-env-'.uniqid()), fn ($p) => $p['niveau'] === $niveau), 'message');
    }

    public function test_une_configuration_saine_ne_donne_aucun_probleme(): void
    {
        $this->assertSame([], ControleProduction::verifier($this->envSain(), sys_get_temp_dir().'/sans-env-'.uniqid()));
    }

    public function test_un_fichier_env_dans_le_conteneur_est_une_erreur(): void
    {
        $dossier = sys_get_temp_dir().'/avec-env-'.uniqid();
        mkdir($dossier);
        file_put_contents($dossier.'/.env', "APP_KEY=x\n");

        $erreurs = array_filter(ControleProduction::verifier($this->envSain(), $dossier), fn ($p) => $p['niveau'] === 'erreur' && $p['regle'] === 1);

        unlink($dossier.'/.env');
        rmdir($dossier);

        $this->assertCount(1, $erreurs);
    }

    public function test_les_reglages_dangereux_sont_des_erreurs(): void
    {
        $cas = [
            'APP_KEY' => [['APP_KEY' => ''], 'APP_KEY'],
            'mot de passe de développement' => [['DB_PASSWORD' => 'koudmain_dev'], 'DB_PASSWORD'],
            'débogage' => [['APP_DEBUG' => 'true'], 'APP_DEBUG'],
            'http' => [['APP_URL' => 'http://koudmain.onrender.com'], 'APP_URL'],
            'localhost' => [['APP_URL' => 'https://localhost'], 'APP_URL'],
            'proxy' => [['TRUST_PROXY' => 'false'], 'TRUST_PROXY'],
            'cookie non sécurisé' => [['SESSION_SECURE_COOKIE' => 'false'], 'SESSION_SECURE_COOKIE'],
            'bcrypt faible' => [['BCRYPT_ROUNDS' => '4'], 'BCRYPT_ROUNDS'],
            'paiement simulé' => [['PAIEMENT_DRIVER' => 'simulation'], 'simulation'],
            'e-mails écrits dans un fichier' => [['MAIL_MAILER' => 'log'], 'MAIL_MAILER'],
        ];

        foreach ($cas as $nom => [$surcharge, $motCle]) {
            $trouve = array_filter($this->messages($this->envSain($surcharge)), fn ($m) => str_contains($m, $motCle));
            $this->assertNotEmpty($trouve, "« $nom » aurait dû être une erreur.");
        }
    }

    public function test_cinetpay_exige_ses_trois_secrets(): void
    {
        $sans = $this->messages($this->envSain(['PAIEMENT_DRIVER' => 'cinetpay']));
        $this->assertCount(3, $sans);

        $avec = $this->messages($this->envSain(['PAIEMENT_DRIVER' => 'cinetpay', 'CINETPAY_API_KEY' => 'a', 'CINETPAY_SITE_ID' => 'b', 'CINETPAY_SECRET_KEY' => 'c']));
        $this->assertSame([], $avec);
    }

    public function test_une_variable_vite_ne_peut_pas_porter_un_secret(): void
    {
        $erreurs = $this->messages($this->envSain(['VITE_SUPABASE_SERVICE_KEY' => 'x', 'VITE_STRIPE_SECRET' => 'y', 'VITE_APP_NAME' => 'KoudMain', 'VITE_SUPABASE_PUBLISHABLE_KEY' => 'ok']));

        $this->assertCount(2, $erreurs);
        $this->assertStringContainsString('VITE_SUPABASE_SERVICE_KEY', implode(' ', $erreurs));
    }

    public function test_les_messages_ne_contiennent_jamais_la_valeur_d_un_secret(): void
    {
        $secret = 'MOT-DE-PASSE-SECRET-A-NE-JAMAIS-AFFICHER';
        $env = $this->envSain(['DB_PASSWORD' => $secret, 'APP_DEBUG' => 'true', 'VITE_MA_CLE_SECRET' => $secret, 'APP_KEY' => '']);

        $this->assertStringNotContainsString($secret, json_encode(ControleProduction::verifier($env, sys_get_temp_dir())));
    }

    public function test_les_points_d_attention_ne_bloquent_pas(): void
    {
        $attentions = $this->messages($this->envSain(['DB_SSLMODE' => 'prefer', 'LOG_LEVEL' => 'debug', 'SESSION_ENCRYPT' => 'false']), 'attention');

        $this->assertCount(3, $attentions);
        $this->assertSame([], $this->messages($this->envSain(['DB_SSLMODE' => 'prefer', 'LOG_LEVEL' => 'debug', 'SESSION_ENCRYPT' => 'false'])));
    }

    public function test_la_commande_est_sans_objet_hors_production(): void
    {
        $this->artisan('koudmain:controle-production')->expectsOutputToContain('sans objet')->assertSuccessful();
    }
}
