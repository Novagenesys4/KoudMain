<?php

namespace Tests\Feature\Securite;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Règles 1, 2, 18 et 20 : rien de secret dans le dépôt ou l'image, déploiement sûr, CI de sécurité et sauvegardes en place. */
class DepotEtDeploiementTest extends TestCase
{
    /** Variables qui sont des secrets : dans render.yaml, jamais avec une valeur (sync: false = saisie dans le tableau de bord). */
    private const SECRETS = ['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'SUPABASE_SERVICE_KEY', 'MAIL_PASSWORD', 'MAIL_USERNAME', 'CINETPAY_API_KEY', 'CINETPAY_SECRET_KEY', 'SENTRY_DSN'];

    private function lire(string $chemin): string
    {
        $contenu = file_get_contents(base_path($chemin));
        $this->assertNotFalse($contenu, "$chemin est introuvable");

        return $contenu;
    }

    /** @return array<string, array<string, mixed>> les variables de render.yaml, par nom */
    private function variablesRender(): array
    {
        $render = Yaml::parse($this->lire('render.yaml'));
        $variables = [];

        foreach ($render['services'][0]['envVars'] as $variable) {
            $variables[$variable['key']] = $variable;
        }

        return $variables;
    }

    // ------------------------------------------------------------------ Règles 1 et 2 : secrets

    public function test_gitignore_protege_les_fichiers_de_secrets_et_de_sauvegarde(): void
    {
        $gitignore = $this->lire('.gitignore');

        foreach (['.env', '.env.*', '!.env.example', '*.pem', '*.key', '*.dump', '*.dump.age', 'secrets.json'] as $motif) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($motif, '/').'$/m', $gitignore, "$motif manque dans .gitignore");
        }
    }

    public function test_l_image_docker_n_embarque_ni_env_ni_historique_ni_secret(): void
    {
        $dockerignore = $this->lire('.dockerignore');

        foreach (['.env', '.env.*', '.git', 'tests'] as $motif) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($motif, '/').'$/m', $dockerignore, "$motif manque dans .dockerignore");
        }

        $dockerfile = $this->lire('Dockerfile');
        $this->assertDoesNotMatchRegularExpression('/^\s*(COPY|ADD)\s+.*\.env\b(?!\.example)/mi', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^\s*(ENV|ARG)\s+\w*(PASSWORD|SECRET|_KEY|TOKEN)\w*\s*[= ]\s*\S+/mi', $dockerfile, 'Un secret est écrit dans le Dockerfile.');
    }

    public function test_render_yaml_ne_contient_aucune_valeur_secrete(): void
    {
        $variables = $this->variablesRender();

        foreach (self::SECRETS as $nom) {
            if (! isset($variables[$nom])) {
                continue; // facultatif (ex. CinetPay tant qu'il n'est pas activé)
            }

            $this->assertFalse(isset($variables[$nom]['value']), "$nom a une valeur dans render.yaml : elle serait publiée dans Git.");
            $this->assertFalse($variables[$nom]['sync'] ?? true, "$nom doit être « sync: false » (saisi dans le tableau de bord de Render).");
        }

        $this->assertArrayHasKey('APP_KEY', $variables);
        $this->assertArrayHasKey('DB_PASSWORD', $variables);
    }

    public function test_render_yaml_impose_une_configuration_de_production_sure(): void
    {
        $v = $this->variablesRender();

        $this->assertSame('production', $v['APP_ENV']['value']);
        $this->assertSame('false', $v['APP_DEBUG']['value']);
        $this->assertSame('true', $v['TRUST_PROXY']['value']);
        $this->assertSame('true', $v['SESSION_SECURE_COOKIE']['value']);
        $this->assertSame('true', $v['SESSION_ENCRYPT']['value']);
        $this->assertLessThanOrEqual(120, (int) $v['SESSION_LIFETIME']['value']);
        $this->assertSame('strict', $v['CONTROLE_PRODUCTION']['value']);
        $this->assertSame('require', $v['DB_SSLMODE']['value']);
        $this->assertNotSame('simulation', $v['PAIEMENT_DRIVER']['value']);
        $this->assertSame('smtp', $v['MAIL_MAILER']['value']);
        $this->assertSame('/up', Yaml::parse($this->lire('render.yaml'))['services'][0]['healthCheckPath']);
    }

    public function test_la_configuration_de_render_passe_son_propre_controle_de_production(): void
    {
        // Ce que Render fournirait (valeurs de render.yaml + secrets fictifs) doit être accepté par koudmain:controle-production.
        $env = ['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)), 'APP_URL' => 'https://koudmain.onrender.com', 'DB_PASSWORD' => 'Un-Vrai-Mot-De-Passe-Long-9182',
            'MAIL_PASSWORD' => 'x', 'MAIL_USERNAME' => 'x', 'DB_USERNAME' => 'postgres.abc'];

        foreach ($this->variablesRender() as $nom => $variable) {
            if (isset($variable['value'])) {
                $env[$nom] = (string) $variable['value'];
            }
        }

        $erreurs = array_filter(\App\Support\ControleProduction::verifier($env, sys_get_temp_dir().'/sans-env-'.uniqid()), fn ($p) => $p['niveau'] === 'erreur');

        $this->assertSame([], array_values($erreurs), 'render.yaml donnerait une configuration refusée au démarrage.');
    }

    public function test_le_conteneur_lance_les_controles_de_securite_au_demarrage(): void
    {
        $entree = $this->lire('docker/entrypoint.sh');

        $this->assertStringContainsString('koudmain:controle-production', $entree);
        $this->assertStringContainsString('koudmain:securiser-base', $entree);
        // Le contrôle vient AVANT les migrations et Apache : une configuration refusée n'ouvre jamais la base ni le site.
        $this->assertLessThan(strpos($entree, 'artisan migrate'), strpos($entree, 'koudmain:controle-production'));
        $this->assertLessThan(strpos($entree, 'artisan migrate'), strpos($entree, 'artisan optimize'));
        $this->assertGreaterThan(strpos($entree, 'artisan migrate'), strpos($entree, 'koudmain:securiser-base'));
    }

    public function test_le_fichier_d_exemple_ne_contient_pas_de_mot_de_passe_de_production(): void
    {
        $exemple = $this->lire('.env.example');

        $this->assertStringContainsString('APP_KEY=', $exemple);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=koudmain_dev$/m', $exemple); // mot de passe de la base LOCALE, refusé en production
        $this->assertDoesNotMatchRegularExpression('/supabase\.co\/|pooler\.supabase\.com|onrender\.com/i', preg_replace('/^#.*$/m', '', $exemple));
    }

    // ------------------------------------------------------------------ Règles 2 et 18 : CI

    public function test_l_integration_continue_scanne_les_secrets_et_les_dependances(): void
    {
        $ci = Yaml::parse($this->lire('.github/workflows/securite.yml'));
        $texte = $this->lire('.github/workflows/securite.yml');

        $this->assertArrayHasKey('secrets', $ci['jobs']);
        $this->assertArrayHasKey('dependances', $ci['jobs']);
        $this->assertArrayHasKey('tests', $ci['jobs']);
        $this->assertStringContainsString('fetch-depth: 0', $texte, 'gitleaks doit voir tout l\'historique.');
        $this->assertStringContainsString('gitleaks git', $texte);
        $this->assertStringContainsString('sha256sum -c', $texte);
        $this->assertStringContainsString('composer audit --locked', $texte);
        $this->assertStringContainsString('npm audit', $texte);
        $this->assertArrayHasKey('schedule', $ci[true] ?? $ci['on'] ?? [], 'Un audit hebdomadaire détecte les failles publiées sans changement de code.');
        $this->assertSame(['contents' => 'read'], $ci['permissions']);
    }

    public function test_dependabot_surveille_composer_npm_et_docker(): void
    {
        $dependabot = Yaml::parse($this->lire('.github/dependabot.yml'));
        $ecosystemes = array_column($dependabot['updates'], 'package-ecosystem');

        foreach (['composer', 'npm', 'docker', 'github-actions'] as $e) {
            $this->assertContains($e, $ecosystemes);
        }
    }

    public function test_la_configuration_gitleaks_detecte_les_secrets_de_l_application_et_ignore_les_exemples(): void
    {
        $gitleaks = $this->lire('.gitleaks.toml');

        $this->assertStringContainsString('useDefault = true', $gitleaks);
        $this->assertStringContainsString('koudmain-postgres-url', $gitleaks);
        $this->assertStringContainsString('koudmain-secret-assigne', $gitleaks);
        $this->assertStringContainsString('koudmain_dev', $gitleaks);
    }

    public function test_les_dependances_ne_sont_pas_figees_sur_des_versions_de_developpement(): void
    {
        $composer = json_decode($this->lire('composer.json'), true);

        $this->assertNotContains('dev-master', $composer['require']);
        $this->assertFileExists(base_path('composer.lock'));
        $this->assertFileExists(base_path('package-lock.json'));
        $this->assertSame('stable', $composer['minimum-stability'] ?? 'stable');
    }

    // ------------------------------------------------------------------ Règle 20 : sauvegardes

    public function test_la_sauvegarde_est_nocturne_chiffree_verifiee_et_envoyee_hors_de_supabase(): void
    {
        $texte = $this->lire('.github/workflows/sauvegarde.yml');
        $sauvegarde = Yaml::parse($texte);

        $this->assertNotEmpty($sauvegarde[true]['schedule'] ?? $sauvegarde['on']['schedule'] ?? null, 'La sauvegarde doit être planifiée.');
        $this->assertStringContainsString('pg_dump', $texte);
        $this->assertStringContainsString('age -r', $texte);                       // chiffrée avec la clé PUBLIQUE
        $this->assertStringNotContainsString('age -d', $texte);                    // la clé privée n'est jamais dans la CI
        $this->assertStringContainsString('pg_restore', $texte);                   // restaurée pour preuve
        $this->assertStringContainsString('r2.cloudflarestorage.com', $texte);     // stockée chez un autre fournisseur
        $this->assertStringContainsString('head-object', $texte);                  // envoi vérifié
        $this->assertStringContainsString('secrets.BACKUP_DATABASE_URL', $texte);  // jamais écrit dans le fichier
        $this->assertDoesNotMatchRegularExpression('/postgres(ql)?:\/\/[^\s$]+:[^\s$@]+@/i', $texte, 'Une adresse de base avec mot de passe est écrite dans le workflow.');
        $this->assertSame(['contents' => 'read'], $sauvegarde['permissions']);
    }

    public function test_le_script_de_restauration_confirme_la_cible_et_ne_laisse_rien_en_clair(): void
    {
        $script = $this->lire('scripts/restaurer.sh');

        $this->assertStringStartsWith('#!/usr/bin/env bash', $script);
        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('trap ', $script);                       // la sauvegarde déchiffrée est effacée
        $this->assertStringContainsString('Retapez le nom de la base', $script);   // confirmation avant d'écrire
        $this->assertStringContainsString('umask 077', $script);
        $this->assertStringContainsString('--ecraser', $script);                   // jamais de DROP sans demande explicite
    }

    public function test_l_inventaire_des_secrets_n_affiche_jamais_une_valeur(): void
    {
        $secret = 'VALEUR-SECRETE-A-NE-JAMAIS-AFFICHER-12345';
        putenv("DB_PASSWORD=$secret");
        $_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = $secret;

        try {
            $this->artisan('koudmain:inventaire-secrets')
                ->expectsOutputToContain('APP_KEY')
                ->expectsOutputToContain('DB_PASSWORD')
                ->doesntExpectOutputToContain($secret)
                ->assertSuccessful();
        } finally {
            putenv('DB_PASSWORD=koudmain_dev');
            $_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = 'koudmain_dev';
        }
    }
}
