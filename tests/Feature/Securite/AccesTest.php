<?php

namespace Tests\Feature\Securite;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\CreeDesCartes;
use Tests\Concerns\CreeDesCommandes;
use Tests\TestCase;

/**
 * Règle 6 : les droits sont vérifiés CÔTÉ SERVEUR, pour chaque adresse, jamais seulement en cachant un bouton.
 *
 * Les deux premiers tests parcourent TOUTES les routes déclarées : une nouvelle page ajoutée demain sans protection fait échouer la
 * suite sans qu'il faille écrire un test. Les suivants vérifient qu'un utilisateur ne peut pas agir sur les données d'un autre
 * (IDOR : « Insecure Direct Object Reference », remplacer un numéro dans l'adresse par celui de quelqu'un d'autre).
 */
class AccesTest extends TestCase
{
    use CreeDesCartes, CreeDesCommandes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerQuartier();
    }

    /** @return list<LaRoute> les routes qui exigent d'être connecté */
    private function routesProtegees(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaRoute $r) => in_array('auth', $r->gatherMiddleware(), true) && ! str_starts_with($r->uri(), '_') && $r->uri() !== 'temps-reel',
        ));
    }

    /** Une adresse qui correspond à la route : chaque paramètre reçoit une valeur qui respecte sa contrainte (where). */
    private function adresse(LaRoute $route): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($route): string {
            $regle = $route->wheres[$m[1]] ?? null;

            foreach (['1', 'annuler', 'ABCDEFGHIJ12', '123e4567-e89b-12d3-a456-426614174000'] as $valeur) {
                if ($regle === null || preg_match('#^(?:'.$regle.')$#', $valeur) === 1) {
                    return $valeur;
                }
            }

            return '1';
        }, $route->uri());
    }

    private function appeler(LaRoute $route)
    {
        return $this->call(array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0], $this->adresse($route));
    }

    public function test_toute_page_reservee_renvoie_le_visiteur_vers_la_connexion(): void
    {
        $this->assertNotEmpty($this->routesProtegees());
        $exposees = [];

        foreach ($this->routesProtegees() as $route) {
            $reponse = $this->appeler($route);

            if (! $reponse->isRedirect(route('connexion'))) {
                $exposees[] = implode('|', $route->methods()).' /'.$route->uri().' → '.$reponse->getStatusCode();
            }
        }

        $this->assertSame([], $exposees, "Accessibles sans connexion :\n".implode("\n", $exposees));
    }

    public function test_toute_page_de_role_refuse_les_utilisateurs_qui_n_ont_pas_ce_role(): void
    {
        $utilisateurs = [
            'client' => User::factory()->create(),
            'prestataire' => User::factory()->prestataire()->create(),
            'prestataire non validé' => User::factory()->prestataire()->enAttente()->create(),
            'admin' => User::factory()->admin()->create(),
        ];
        $exposees = [];
        $verifiees = 0;

        foreach ($this->routesProtegees() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'role:')) {
                    continue;
                }

                $roles = explode(',', substr($middleware, 5));

                foreach ($utilisateurs as $nom => $utilisateur) {
                    $autorise = collect($roles)->contains(fn ($role) => $utilisateur->aLeRole($role));

                    if ($autorise) {
                        continue;
                    }

                    $verifiees++;
                    $this->app['auth']->forgetGuards();
                    // Sans « substitution des modèles » : un numéro inexistant donnerait 404 avant même que le rôle soit vérifié.
                    $reponse = $this->actingAs($utilisateur)->withoutMiddleware([SubstituteBindings::class, ThrottleRequests::class])->call(
                        array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']))[0],
                        $this->adresse($route),
                    );

                    if ($reponse->getStatusCode() !== 403) {
                        $exposees[] = "[$nom] ".implode('|', $route->methods()).' /'.$route->uri().' → '.$reponse->getStatusCode().' (403 attendu)';
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $verifiees);
        $this->assertSame([], $exposees, "Rôle non vérifié :\n".implode("\n", $exposees));
    }

    public function test_un_prestataire_suspendu_perd_l_acces_immediatement(): void
    {
        $prestataire = User::factory()->prestataire()->create();

        $this->actingAs($prestataire)->get(route('prestataire.prestations.index'))->assertOk();

        $prestataire->forceFill(['est_valide' => false])->save(); // suspension par un administrateur

        $this->actingAs($prestataire->fresh())->get(route('prestataire.prestations.index'))->assertForbidden();
    }

    public function test_les_champs_de_droits_envoyes_dans_un_formulaire_sont_ignores(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->put(route('compte.identite'), [
            'prenom' => 'Aya', 'nom' => 'Koné', 'telephone' => '0712345678', 'quartier_id' => $client->quartier_id,
            'est_admin' => 1, 'est_valide' => 1, 'est_prestataire' => 1, 'email_verified_at' => now()->toDateTimeString(), 'password' => 'Piraté123',
        ])->assertSessionHasNoErrors();

        $client->refresh();
        $this->assertFalse($client->est_admin);
        $this->assertFalse($client->est_prestataire);
        $this->assertSame('Aya', $client->prenom);
        $this->assertFalse(password_verify('Piraté123', $client->password));
    }

    public function test_un_utilisateur_ne_lit_ni_n_ecrit_dans_la_messagerie_d_une_commande_etrangere(): void
    {
        $client = $this->unClient(9000);
        $offre = $this->uneOffre();
        $commande = $this->commander($client, $offre);
        $etranger = $this->unClient();

        $this->actingAs($etranger)->get(route('messages.voir', $commande))->assertStatus(404);
        $this->actingAs($etranger)->getJson(route('messages.fil', $commande))->assertStatus(404);
        $this->actingAs($etranger)->postJson(route('messages.envoyer', $commande), ['contenu' => 'Bonjour'])->assertStatus(404);
        $this->actingAs($etranger)->postJson(route('messages.lu', $commande))->assertStatus(404);
    }

    public function test_un_client_ne_touche_pas_aux_cartes_d_un_autre(): void
    {
        $proprietaire = $this->unClient();
        $carte = $this->uneCarte($proprietaire);
        $voleur = $this->unClient();

        $refus1 = $this->actingAs($voleur)->delete(route('client.wallet.cartes.supprimer', $carte));
        $refus2 = $this->actingAs($voleur)->patch(route('client.wallet.cartes.geler', $carte));

        // Refusé (404/403) ou renvoyé vers son propre portefeuille avec une erreur : dans tous les cas, la carte n'a pas bougé.
        foreach ([$refus1, $refus2] as $reponse) {
            $this->assertTrue(in_array($reponse->getStatusCode(), [403, 404], true) || $reponse->isRedirect(), 'Réponse inattendue : '.$reponse->getStatusCode());
        }

        $this->assertDatabaseHas('cartes_virtuelles', ['id' => $carte->id]);
        $this->assertFalse((bool) $carte->fresh()->est_gelee, 'La carte d\'un autre a été gelée.');
    }

    public function test_un_prestataire_ne_modifie_pas_les_prestations_ni_les_photos_d_un_autre(): void
    {
        $proprietaire = $this->unPrestataire();
        $offre = $this->uneOffre($proprietaire);
        $photo = $this->unePhoto($offre);
        $intrus = $this->unPrestataire();

        $refus = [
            $this->actingAs($intrus)->get(route('prestataire.prestations.modifier', $offre)),
            $this->actingAs($intrus)->put(route('prestataire.prestations.mettre-a-jour', $offre), ['titre' => 'Piraté', 'service_id' => $offre->service_id, 'prix' => 100]),
            $this->actingAs($intrus)->delete(route('prestataire.prestations.supprimer', $offre)),
            $this->actingAs($intrus)->patch(route('prestataire.prestations.activation', $offre)),
            $this->actingAs($intrus)->delete(route('prestataire.prestations.photos.supprimer', [$offre, $photo])),
        ];

        foreach ($refus as $reponse) {
            $this->assertContains($reponse->getStatusCode(), [403, 404]);
        }

        $this->assertDatabaseHas('prestations', ['id' => $offre->id, 'titre' => $offre->titre]);
        $this->assertNotNull(Media::query()->find($photo->id));
    }

    public function test_une_photo_d_une_autre_prestation_ne_peut_pas_etre_supprimee_via_la_sienne(): void
    {
        $prestataire = $this->unPrestataire();
        $mienne = $this->uneOffre($prestataire);
        $autre = $this->uneOffre($this->unPrestataire());
        $photoEtrangere = $this->unePhoto($autre);

        // Le numéro de photo appartient à une autre prestation : l'adresse « mienne + photo étrangère » doit être refusée.
        $reponse = $this->actingAs($prestataire)->delete(route('prestataire.prestations.photos.supprimer', [$mienne, $photoEtrangere]));

        $this->assertContains($reponse->getStatusCode(), [403, 404]);
        $this->assertNotNull(Media::query()->find($photoEtrangere->id));
    }

    public function test_les_notifications_d_un_autre_utilisateur_ne_sont_pas_accessibles(): void
    {
        $proprietaire = $this->unClient();
        $autre = $this->unClient();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $proprietaire->id,
            'data' => json_encode(['titre' => 'Privé']), 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($autre)->post(route('notifications.lire', $id));

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'), 'Une notification étrangère a été marquée comme lue.');
    }

    public function test_un_client_ne_voit_pas_les_retraits_ni_les_pages_de_l_administration(): void
    {
        $client = $this->unClient();

        foreach (['admin.tableau-de-bord', 'admin.utilisateurs', 'admin.retraits', 'admin.metriques', 'admin.commandes', 'admin.prestataires'] as $nom) {
            $this->actingAs($client)->get(route($nom))->assertForbidden();
        }
    }
}
