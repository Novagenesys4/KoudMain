<?php

namespace Tests\Feature\Securite;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Règles 7 et 15 : rien de sensible dans le JavaScript, aucun console.log de production, seules des clés publiques côté navigateur. */
class ConsoleEtSecretsNavigateurTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_construction_de_production_retire_console_et_debugger(): void
    {
        $vite = file_get_contents(base_path('vite.config.js'));

        $this->assertStringContainsString("drop: ['console', 'debugger']", $vite);
        $this->assertStringContainsString('sourcemap: false', $vite);
    }

    public function test_aucun_console_log_debug_info_ou_trace_dans_le_code_source(): void
    {
        // console.error/warn restent tolérés en développement (retirés à la construction) ; log/debug/info/trace/dir/table jamais.
        $trouves = [];

        foreach ($this->fichiers(resource_path('js'), ['js', 'jsx']) as $fichier) {
            foreach (file($fichier) as $n => $ligne) {
                if (preg_match('/\bconsole\.(log|debug|info|trace|dir|table)\b|\bdebugger\b/', $ligne)) {
                    $trouves[] = str_replace(base_path().'/', '', $fichier).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $trouves, 'console.log & co interdits (règle 15) : '.implode(', ', $trouves));
    }

    public function test_les_vues_n_affichent_aucune_variable_d_environnement_ni_secret(): void
    {
        $dangereux = [];

        foreach ($this->fichiers(resource_path('views'), ['php']) as $fichier) {
            $contenu = file_get_contents($fichier);

            if (preg_match('/env\(|config\(\s*[\'"](?:koudmain\.paiement|koudmain\.media\.supabase|app\.key|database|mail\.mailers|services)/', $contenu)) {
                $dangereux[] = str_replace(base_path().'/', '', $fichier);
            }
        }

        $this->assertSame([], $dangereux, 'Ces vues lisent un réglage sensible : '.implode(', ', $dangereux));
    }

    public function test_le_javascript_n_utilise_que_des_variables_publiques(): void
    {
        $this->addToAssertionCount(1); // si aucun fichier n'utilise import.meta.env, c'est déjà conforme

        foreach ($this->fichiers(resource_path('js'), ['js', 'jsx']) as $fichier) {
            preg_match_all('/import\.meta\.env\.(\w+)/', file_get_contents($fichier), $m);

            foreach ($m[1] as $nom) {
                $this->assertTrue(str_starts_with($nom, 'VITE_') || in_array($nom, ['MODE', 'DEV', 'PROD', 'BASE_URL', 'SSR'], true), "$nom n'est pas une variable publique.");
                $this->assertDoesNotMatchRegularExpression('/(SECRET|PRIVATE|PASSWORD|TOKEN|SERVICE|API_?KEY)/i', $nom, "$nom ressemble à un secret dans le navigateur.");
            }
        }
    }

    public function test_le_fichier_d_exemple_ne_contient_aucun_vrai_secret_et_aucune_variable_vite_sensible(): void
    {
        $exemple = file_get_contents(base_path('.env.example'));

        $this->assertDoesNotMatchRegularExpression('/^VITE_\w*(SECRET|PRIVATE|PASSWORD|TOKEN|SERVICE|API_?KEY)\w*=/mi', $exemple);
        // Aucune clé d'application, aucune clé longue collée par erreur.
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $exemple);
        $this->assertDoesNotMatchRegularExpression('/^\w*(SECRET|KEY|PASSWORD|TOKEN|DSN)\w*=\s*[A-Za-z0-9+\/=_\-]{24,}\s*$/mi', $exemple);
    }

    public function test_la_page_d_accueil_ne_laisse_apparaitre_aucun_secret(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('K', 32)),
            'koudmain.paiement.cinetpay.secret_key' => 'CLE-SECRETE-CINETPAY',
            'koudmain.paiement.cinetpay.api_key' => 'CLE-API-CINETPAY',
            'koudmain.media.supabase.cle_service' => 'CLE-SERVICE-SUPABASE',
            'mail.mailers.smtp.password' => 'MOT-DE-PASSE-SMTP',
        ]);

        $pages = [$this->get('/'), $this->get('/connexion'), $this->get('/inscription'), $this->get('/prestations'), $this->get('/introuvable-xyz')];

        foreach ($pages as $reponse) {
            $html = $reponse->getContent();
            foreach (['CLE-SECRETE-CINETPAY', 'CLE-API-CINETPAY', 'CLE-SERVICE-SUPABASE', 'MOT-DE-PASSE-SMTP', base64_encode(str_repeat('K', 32))] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }

    /** @param list<string> $extensions @return list<string> */
    private function fichiers(string $dossier, array $extensions): array
    {
        $trouves = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            $ext = pathinfo($fichier->getFilename(), PATHINFO_EXTENSION);

            if (in_array($ext, $extensions, true) && ! str_ends_with($fichier->getFilename(), '.min.js')) {
                $trouves[] = $fichier->getPathname();
            }
        }

        return $trouves;
    }
}
