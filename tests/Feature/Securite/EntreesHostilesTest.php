<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Règle 10 : validation stricte de toutes les entrées, côté serveur.
 *
 * Ce test « bombarde » chaque page (GET) de chaque espace avec des entrées hostiles : paramètres sous forme de tableau
 * (?q[]=x), textes immenses, identifiants hors limites, octets nuls, caractères de contrôle. Une entrée invalide doit donner une
 * réponse propre (200, redirection, 404 ou 422), JAMAIS une erreur serveur 500 (qui trahit un cas non prévu et, en production,
 * finit dans les alertes).
 */
class EntreesHostilesTest extends TestCase
{
    use RefreshDatabase;

    /** Les paramètres de requête que les pages du site lisent, chacun sous des formes hostiles. */
    private const CLES = ['q', 'statut', 'role', 'type', 'avant', 'depuis', 'jours', 'page', 'tri', 'categorie', 'service', 'zone', 'prix_min', 'prix_max',
        'note_min', 'photo', 'prestataire', 'filtre', 'du', 'au', 'ville', 'quartier', 'per_page', 'sort', 'direction'];

    /** Pages qui restent ouvertes en continu (flux SSE) : les appeler bloquerait le test. */
    private const EXCLUES = ['temps-reel', 'up'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    /** @return list<LaRoute> */
    private function pagesGet(): array
    {
        $pages = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), '_') || str_contains($route->uri(), '{fallbackPlaceholder}')) {
                continue;
            }

            if (in_array($route->uri(), self::EXCLUES, true) || str_starts_with($route->uri(), 'storage/') || str_starts_with($route->uri(), 'email/confirmer')) {
                continue;
            }

            $pages[] = $route;
        }

        return $pages;
    }

    /** @return array<string, array{0: string}> */
    private function formes(): array
    {
        $enorme = str_repeat('é', 6000);

        return [
            'tableau' => ['?'.http_build_query(array_fill_keys(self::CLES, ['a', 'b']))],
            'tableau imbriqué' => ['?'.http_build_query(array_fill_keys(self::CLES, ['a' => ['b' => ['c']]]))],
            'texte immense' => ['?'.http_build_query(array_fill_keys(self::CLES, $enorme))],
            'octet nul et contrôles' => ['?'.http_build_query(array_fill_keys(self::CLES, "a\0b\x01\x1f\u{202E}"))],
            'entier hors limites' => ['?'.http_build_query(array_fill_keys(self::CLES, '99999999999999999999999'))],
            'négatifs et décimaux' => ['?'.http_build_query(array_fill_keys(self::CLES, '-1e999'))],
            'injection SQL' => ['?'.http_build_query(array_fill_keys(self::CLES, "' OR 1=1; DROP TABLE users; --"))],
        ];
    }

    private function url(LaRoute $route, string $valeurParametre): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', $valeurParametre, $route->uri());
    }

    /** @return array<string, User> */
    private function utilisateurs(): array
    {
        return [
            'visiteur' => null,
            'client' => User::factory()->create(),
            'prestataire' => User::factory()->prestataire()->create(),
            'admin' => User::factory()->admin()->create(),
        ];
    }

    public function test_aucune_page_ne_plante_avec_des_parametres_de_requete_hostiles(): void
    {
        $echecs = [];

        foreach ($this->utilisateurs() as $role => $utilisateur) {
            foreach ($this->pagesGet() as $route) {
                foreach ($this->formes() as $forme => [$requete]) {
                    $this->app['auth']->forgetGuards();
                    $utilisateur ? $this->actingAs($utilisateur) : $this->app['auth']->guard('web')->logout();

                    $reponse = $this->get($this->url($route, '1').$requete);

                    if ($reponse->getStatusCode() >= 500) {
                        $echecs[] = "[$role] /{$route->uri()} avec « $forme » → {$reponse->getStatusCode()}";
                    }
                }
            }
        }

        $this->assertSame([], $echecs, "Ces pages plantent (500) au lieu de refuser proprement :\n".implode("\n", $echecs));
    }

    public function test_aucune_page_ne_plante_avec_des_identifiants_d_url_hostiles(): void
    {
        $echecs = [];

        foreach ($this->utilisateurs() as $role => $utilisateur) {
            foreach ($this->pagesGet() as $route) {
                if (! str_contains($route->uri(), '{')) {
                    continue;
                }

                foreach (['99999999999999999999999', '-1', '0', 'abc', "%00", '1%27%20OR%201=1', str_repeat('9', 300), '%E2%80%AE'] as $valeur) {
                    $this->app['auth']->forgetGuards();
                    $utilisateur ? $this->actingAs($utilisateur) : $this->app['auth']->guard('web')->logout();

                    $reponse = $this->get($this->url($route, $valeur));

                    if ($reponse->getStatusCode() >= 500) {
                        $echecs[] = "[$role] {$this->url($route, $valeur)} → {$reponse->getStatusCode()}";
                    }
                }
            }
        }

        $this->assertSame([], $echecs, "Ces adresses plantent (500) au lieu de répondre 404 :\n".implode("\n", array_slice($echecs, 0, 30)));
    }

    /** Champs de formulaire lus par les actions POST/PUT/PATCH/DELETE du site. */
    private const CHAMPS = ['adresse', 'carte_id', 'categorie_id', 'commentaire', 'couleur', 'cvv', 'date', 'decision', 'description', 'destination', 'duree_minutes',
        'email', 'expiration', 'heure', 'libelle', 'methode', 'mode_paiement', 'montant', 'mot_de_passe_actuel', 'motif', 'note', 'numero_carte', 'password',
        'password_confirmation', 'pays', 'photos', 'precisions', 'prenom', 'prestation_id', 'prix', 'quantite', 'quartier_id', 'role', 'service_id', 'telephone',
        'titre', 'nom', 'contenu', 'jours', 'operateur', 'numero', 'notifications_email', 'avatar', 'remember', 'q'];

    /**
     * Même bombardement pour les actions qui MODIFIENT des données : un champ qui arrive sous une forme inattendue (tableau à la place
     * d'un texte, nombre géant, texte immense) doit donner une erreur de validation (422 / retour au formulaire), jamais un 500.
     */
    public function test_aucune_action_ne_plante_avec_des_champs_de_formulaire_hostiles(): void
    {
        $echecs = [];
        $formes = [
            'tableau' => fn () => ['a', 'b'],
            'tableau imbriqué' => fn () => ['a' => ['b' => ['c']]],
            'texte immense' => fn () => str_repeat('é', 70000),
            'entier hors limites' => fn () => '99999999999999999999999',
            'octet nul' => fn () => "a\0b\x01",
            'négatif' => fn () => '-1',
        ];

        foreach ($this->utilisateurs() as $role => $utilisateur) {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $methode = array_values(array_diff($route->methods(), ['HEAD', 'GET', 'OPTIONS']))[0] ?? null;

                if ($methode === null || str_contains($route->uri(), '{fallbackPlaceholder}') || in_array($route->uri(), ['deconnexion', 'paiements/notification'], true) || str_starts_with($route->uri(), '_')) {
                    continue;
                }

                foreach ($formes as $forme => $fabrique) {
                    $this->app['auth']->forgetGuards();
                    $utilisateur ? $this->actingAs($utilisateur) : $this->app['auth']->guard('web')->logout();

                    $reponse = $this->call($methode, $this->url($route, '1'), array_fill_keys(self::CHAMPS, $fabrique()));

                    if ($reponse->getStatusCode() >= 500) {
                        $echecs[] = "[$role] $methode /{$route->uri()} avec « $forme » → {$reponse->getStatusCode()}";
                    }
                }
            }
        }

        $this->assertSame([], $echecs, "Ces actions plantent (500) au lieu de refuser proprement :\n".implode("\n", array_slice($echecs, 0, 40)));
    }
}
