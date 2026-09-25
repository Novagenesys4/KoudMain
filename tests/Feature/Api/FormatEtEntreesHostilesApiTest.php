<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/**
 * Parcourt TOUTES les routes /api/* (une route ajoutée demain est testée d'office) :
 *  - aucune ne plante (500) avec des entrées hostiles, en visiteur, en client ou en prestataire ;
 *  - toutes répondent avec l'enveloppe standard { success, message, data } ;
 *  - toutes les routes hors catalogue public et authentification exigent un jeton.
 */
class FormatEtEntreesHostilesApiTest extends TestCase
{
    use CreeDesCommandes, RefreshDatabase;

    private const CHAMPS = ['login', 'password', 'password_confirmation', 'nom', 'prenom', 'email', 'telephone', 'quartier_id', 'role', 'device_name',
        'verification_id', 'code', 'prestation_id', 'quantite', 'date', 'heure', 'precisions', 'mode_paiement', 'carte_id', 'motif',
        'note', 'commentaire', 'contenu', 'montant', 'methode', 'destination'];

    private const PUBLIQUES = ['api/v1/referentiel/categories', 'api/v1/referentiel/zones', 'api/v1/parametres', 'api/v1/prestations',
        'api/v1/prestations/{prestation}', 'api/v1/prestations/{prestation}/creneaux', 'api/v1/prestataires/{prestataire}'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
        config(['koudmain.api.sms.driver' => 'journal', 'koudmain.paiement.driver' => 'simulation']);
        // Sans plafond de requêtes : une réponse 429 masquerait une éventuelle erreur 500 derrière.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return list<LaRoute> */
    private function routesApi(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (LaRoute $r) => str_starts_with($r->uri(), 'api/')));
    }

    private function url(LaRoute $route): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => match ($m[1]) {
            'notification' => '123e4567-e89b-12d3-a456-426614174000',
            'reference' => 'KM260921ABCDEF',
            'action' => 'annuler',
            'prestation' => 'une-prestation-abc123',
            default => '1',
        }, $route->uri());
    }

    public function test_toutes_les_routes_privees_exigent_un_jeton(): void
    {
        $ouvertes = [];

        foreach ($this->routesApi() as $route) {
            if (in_array($route->uri(), self::PUBLIQUES, true) || str_starts_with($route->uri(), 'api/v1/auth/') && ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            $methode = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0];
            $reponse = $this->json($methode, $this->url($route));

            if ($reponse->getStatusCode() !== 401) {
                $ouvertes[] = "$methode /{$route->uri()} → {$reponse->getStatusCode()}";
            }
        }

        $this->assertSame([], $ouvertes, "Accessibles sans jeton :\n".implode("\n", $ouvertes));
    }

    public function test_aucune_route_ne_plante_et_la_forme_est_toujours_standard(): void
    {
        $offre = $this->uneOffre();
        $client = $this->unClient(10000);
        $client->forceFill(['telephone_verifie_at' => now()])->save();
        $pro = $offre->prestataire;
        $pro->forceFill(['telephone_verifie_at' => now()])->save();

        $formes = [
            'vide' => fn () => '',
            'tableau' => fn () => ['a', ['b']],
            'texte immense' => fn () => str_repeat('é', 20000),
            'entier hors limites' => fn () => '99999999999999999999999',
            'octet nul' => fn () => "a\0b\x01",
            'négatif' => fn () => '-1',
            'injection' => fn () => "' OR 1=1; --",
        ];

        $echecs = [];
        $statuts = [];

        foreach (['visiteur' => null, 'client' => $client, 'prestataire' => $pro] as $qui => $utilisateur) {
            foreach ($this->routesApi() as $route) {
                $methode = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0];

                foreach ($formes as $forme => $fabrique) {
                    $this->app['auth']->forgetGuards();

                    if ($utilisateur !== null) {
                        Sanctum::actingAs($utilisateur);
                    }

                    $donnees = array_fill_keys(self::CHAMPS, $fabrique());
                    $reponse = $methode === 'GET'
                        ? $this->getJson($this->url($route).'?'.http_build_query(['q' => $fabrique(), 'page' => $fabrique(), 'avant' => $fabrique(), 'type' => $fabrique()]))
                        : $this->json($methode, $this->url($route), $donnees);

                    $corps = $reponse->json();
                    $statuts[$reponse->getStatusCode()] = ($statuts[$reponse->getStatusCode()] ?? 0) + 1;

                    if ($reponse->getStatusCode() >= 500 && $reponse->getStatusCode() !== 503) {
                        $echecs[] = "[$qui] $methode /{$route->uri()} « $forme » → {$reponse->getStatusCode()}";
                    } elseif (! is_array($corps) || ! array_key_exists('success', $corps) || ! array_key_exists('message', $corps) || ! array_key_exists('data', $corps)) {
                        $echecs[] = "[$qui] $methode /{$route->uri()} « $forme » → forme non standard";
                    }
                }
            }
        }

        $this->assertArrayNotHasKey(429, $statuts);
        $this->assertSame([], $echecs, implode("\n", array_slice($echecs, 0, 40)));
    }
}
