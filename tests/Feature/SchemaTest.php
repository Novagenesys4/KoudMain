<?php

namespace Tests\Feature;

use App\Models\Prestation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le code applicatif valide déjà tout ; ces tests vérifient l'ULTIME barrière : les contraintes
 * de PostgreSQL. Si un bogue (ou une requête manuelle) contourne la validation, la base refuse.
 * Chaque test passe par DB::table(), donc sans aucune règle Eloquent ni FormRequest.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        $this->creerQuartier();

        return User::factory()->create();
    }

    private function prestataire(): User
    {
        $this->creerQuartier();

        return User::factory()->prestataire()->create();
    }

    private function service(): int
    {
        $categorie = DB::table('categories')->insertGetId(['nom' => 'Beauté '.uniqid()]);

        return DB::table('services')->insertGetId(['nom' => 'Coiffure', 'categorie_id' => $categorie]);
    }

    private function commande(User $client, User $prestataire): int
    {
        return DB::table('commandes')->insertGetId([
            'client_id' => $client->id,
            'prestataire_id' => $prestataire->id,
            'quartier_id' => $client->quartier_id,
            'montant_total' => 5000,
            'statut' => 'en_attente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prestation(User $prestataire, array $surcharge = []): int
    {
        return DB::table('prestations')->insertGetId(array_merge([
            'prestataire_id' => $prestataire->id,
            'service_id' => $this->service(),
            'titre' => 'Coiffure à domicile',
            'description' => 'Tresses et coupes',
            'prix' => 5000,
            'slug' => 'coiffure-a-domicile-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $surcharge));
    }

    public function test_un_escrow_de_montant_zero_est_refuse(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);

        $this->expectException(QueryException::class);

        DB::table('escrows')->insert([
            'commande_id' => $commande, 'client_id' => $client->id, 'prestataire_id' => $prestataire->id,
            'montant' => 0, 'statut' => 'bloque',
        ]);
    }

    public function test_une_commande_ne_peut_avoir_qu_un_escrow(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);
        $ligne = ['commande_id' => $commande, 'client_id' => $client->id, 'prestataire_id' => $prestataire->id, 'montant' => 5000];

        DB::table('escrows')->insert($ligne);

        $this->expectException(QueryException::class);
        DB::table('escrows')->insert($ligne);
    }

    public function test_un_statut_de_commande_inconnu_est_refuse(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);

        $this->expectException(QueryException::class);

        DB::table('commandes')->where('id', $commande)->update(['statut' => 'remboursee']);
    }

    public function test_les_six_statuts_de_commande_sont_acceptes(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);

        foreach (['en_attente', 'acceptee', 'en_cours', 'terminee', 'annulee', 'litige'] as $statut) {
            DB::table('commandes')->where('id', $commande)->update(['statut' => $statut]);
            $this->assertSame($statut, DB::table('commandes')->where('id', $commande)->value('statut'));
        }
    }

    public function test_une_note_hors_de_1_a_5_est_refusee(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);
        $prestation = $this->prestation($prestataire);

        $this->expectException(QueryException::class);

        DB::table('avis')->insert([
            'commande_id' => $commande, 'prestation_id' => $prestation, 'user_id' => $client->id,
            'note' => 6, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_seul_avis_par_commande_et_prestation(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);
        $prestation = $this->prestation($prestataire);
        $avis = [
            'commande_id' => $commande, 'prestation_id' => $prestation, 'user_id' => $client->id,
            'note' => 5, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('avis')->insert($avis);

        $this->expectException(QueryException::class);
        DB::table('avis')->insert($avis);
    }

    public function test_deux_e_mails_qui_ne_different_que_par_la_casse_sont_refuses(): void
    {
        $this->creerQuartier();
        User::factory()->create(['email' => 'awa@exemple.ci']);

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'nom' => 'Kone', 'prenom' => 'Awa', 'email' => 'AWA@Exemple.CI', 'telephone' => '0700000000',
            'password' => 'x', 'quartier_id' => User::query()->value('quartier_id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_e_mail_sans_arobase_est_refuse(): void
    {
        $this->creerQuartier();

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'pas-un-email']);
    }

    public function test_une_disponibilite_dont_la_fin_precede_le_debut_est_refusee(): void
    {
        $prestataire = $this->prestataire();

        $this->expectException(QueryException::class);

        DB::table('disponibilites')->insert([
            'user_id' => $prestataire->id, 'jour' => 1, 'heure_debut' => '17:00', 'heure_fin' => '09:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_jour_de_disponibilite_hors_de_1_a_7_est_refuse(): void
    {
        $prestataire = $this->prestataire();

        $this->expectException(QueryException::class);

        DB::table('disponibilites')->insert([
            'user_id' => $prestataire->id, 'jour' => 8, 'heure_debut' => '09:00', 'heure_fin' => '17:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_message_vide_ou_fait_d_espaces_est_refuse(): void
    {
        $client = $this->client();
        $prestataire = User::factory()->prestataire()->create();
        $commande = $this->commande($client, $prestataire);
        $conversation = DB::table('conversations')->insertGetId([
            'commande_id' => $commande, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'conversation_id' => $conversation, 'expediteur_id' => $client->id, 'contenu' => '   ',
        ]);
    }

    public function test_un_prix_negatif_ou_nul_est_refuse(): void
    {
        $prestataire = $this->prestataire();

        $this->expectException(QueryException::class);

        $this->prestation($prestataire, ['prix' => 0]);
    }

    public function test_un_solde_de_wallet_negatif_est_refuse(): void
    {
        $client = $this->client();

        $this->expectException(QueryException::class);

        DB::table('wallets')->insert([
            'user_id' => $client->id, 'solde' => -1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_le_modele_prestation_genere_un_slug_unique_et_lisible(): void
    {
        $prestataire = $this->prestataire();
        $service = $this->service();

        $a = $prestataire->prestations()->create(['service_id' => $service, 'titre' => 'Coiffure Tresses', 'prix' => 5000]);
        $b = $prestataire->prestations()->create(['service_id' => $service, 'titre' => 'Coiffure Tresses', 'prix' => 5000]);

        $this->assertMatchesRegularExpression('/^coiffure-tresses-[a-z0-9]{6}$/', $a->slug);
        $this->assertNotSame($a->slug, $b->slug);
        $this->assertSame($a->id, Prestation::query()->where('slug', $a->slug)->value('id'));
    }

    public function test_la_recherche_plein_texte_francaise_ignore_pluriels_et_conjugaisons(): void
    {
        $prestataire = $this->prestataire();
        $this->prestation($prestataire, ['titre' => 'Coiffure à domicile', 'description' => 'Tresses et coupes']);
        $this->prestation($prestataire, ['titre' => 'Réparation de fuites', 'description' => 'Plomberie rapide']);

        $trouve = fn (string $mots) => DB::table('prestations')
            ->whereRaw("search_vector @@ plainto_tsquery('french', immutable_unaccent(?))", [$mots])
            ->pluck('titre')
            ->all();

        $this->assertSame(['Coiffure à domicile'], $trouve('coiffures'));
        $this->assertSame(['Réparation de fuites'], $trouve('réparer fuite'));
        $this->assertSame([], $trouve('jardinage'));
    }

    public function test_la_recherche_ignore_les_accents_dans_les_deux_sens(): void
    {
        $prestataire = $this->prestataire();
        $this->prestation($prestataire, ['titre' => 'Réparation électrique', 'description' => 'Dépannage à domicile']);

        $trouve = fn (string $mots) => DB::table('prestations')
            ->whereRaw("search_vector @@ plainto_tsquery('french', immutable_unaccent(?))", [$mots])
            ->count();

        $this->assertSame(1, $trouve('reparation electrique'), 'sans accents');
        $this->assertSame(1, $trouve('RÉPARATION ÉLECTRIQUE'), 'avec accents et majuscules');
        $this->assertSame(1, $trouve('depannage'), 'la description compte aussi');
    }

    public function test_immutable_unaccent_est_bien_declaree_immutable(): void
    {
        // Sans cela, PostgreSQL refuserait la colonne générée : on vérifie l'attribut lui-même.
        $this->assertSame('i', DB::selectOne("SELECT provolatile FROM pg_proc WHERE proname = 'immutable_unaccent'")->provolatile);
        $this->assertSame('ecole', DB::selectOne("SELECT immutable_unaccent('école') AS t")->t);
        $this->assertNull(DB::selectOne('SELECT immutable_unaccent(NULL) AS t')->t);
    }

    public function test_la_duree_d_une_prestation_est_controlee_par_la_base(): void
    {
        $prestataire = $this->prestataire();
        $service = $this->service();
        $base = ['prestataire_id' => $prestataire->id, 'service_id' => $service, 'titre' => 'Coiffure', 'prix' => 5000, 'slug' => 'coiffure-aaaaaa', 'created_at' => now(), 'updated_at' => now()];

        DB::table('prestations')->insert($base + ['duree_minutes' => 90]);
        DB::table('prestations')->insert(array_merge($base, ['slug' => 'coiffure-bbbbbb', 'duree_minutes' => null]));
        $this->assertSame(2, DB::table('prestations')->count());

        $this->expectException(QueryException::class);
        DB::table('prestations')->insert(array_merge($base, ['slug' => 'coiffure-cccccc', 'duree_minutes' => 5]));
    }

    public function test_un_utilisateur_ne_peut_avoir_qu_un_seul_avatar(): void
    {
        $utilisateur = $this->client();
        $avatar = ['mediable_type' => User::class, 'mediable_id' => $utilisateur->id, 'type' => 'avatar', 'disk' => 'local', 'chemin' => 'avatars/1/a.webp', 'mime' => 'image/webp', 'taille_octets' => 1000, 'created_at' => now(), 'updated_at' => now()];

        DB::table('medias')->insert($avatar);

        $this->expectException(QueryException::class);
        DB::table('medias')->insert(array_merge($avatar, ['chemin' => 'avatars/1/b.webp']));
    }

    public function test_le_type_et_le_poids_d_un_media_sont_controles(): void
    {
        $prestataire = $this->prestataire();
        $prestation = $this->prestation($prestataire);
        $base = [
            'mediable_type' => Prestation::class, 'mediable_id' => $prestation, 'type' => 'photo_prestation',
            'disk' => 'public', 'chemin' => 'prestations/a.jpg', 'mime' => 'image/jpeg', 'taille_octets' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('medias')->insert($base);
        $this->assertSame(1, DB::table('medias')->count());

        $this->expectException(QueryException::class);
        DB::table('medias')->insert(array_merge($base, ['mime' => 'application/x-php']));
    }
    // ------------------------------------------------------------------ Lot 6 : mode de paiement et cartes saisies

    public function test_un_mode_de_paiement_inconnu_est_refuse_et_les_trois_valides_sont_acceptes(): void
    {
        $client = $this->client();
        $prestataire = $this->prestataire();
        $commande = $this->commande($client, $prestataire);

        $this->assertSame('mobile_money', DB::table('commandes')->where('id', $commande)->value('mode_paiement'), 'valeur par défaut : les commandes existantes restent en Mobile Money');

        foreach (['physique', 'mobile_money', 'carte'] as $mode) {
            DB::table('commandes')->where('id', $commande)->update(['mode_paiement' => $mode]);
            $this->assertSame($mode, DB::table('commandes')->where('id', $commande)->value('mode_paiement'));
        }

        $this->expectException(QueryException::class);
        DB::table('commandes')->where('id', $commande)->update(['mode_paiement' => 'bitcoin']);
    }

    private function carte(int $walletId, array $surcharge = []): int
    {
        return DB::table('cartes_virtuelles')->insertGetId(array_merge([
            'wallet_id' => $walletId, 'libelle' => 'Ma carte', 'type_carte' => 'visa', 'couleur' => 'emerald', 'numero_masque' => '**** **** **** 4242',
            'nom_titulaire' => 'AWA KONÉ', 'date_expiration' => '12/29', 'est_principale' => false, 'est_gelee' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $surcharge));
    }

    private function walletDe(User $u): int
    {
        return app(\App\Services\WalletService::class)->pour($u)->id;
    }

    public function test_american_express_est_un_reseau_accepte_mais_pas_discover(): void
    {
        $wallet = $this->walletDe($this->client());

        $this->carte($wallet, ['type_carte' => 'amex', 'numero_masque' => '**** ****** *0005']);
        $this->assertSame(1, DB::table('cartes_virtuelles')->where('type_carte', 'amex')->count());

        $this->expectException(QueryException::class);
        $this->carte($wallet, ['type_carte' => 'discover']);
    }

    public function test_la_meme_empreinte_ne_peut_pas_etre_ajoutee_deux_fois_dans_un_wallet_mais_deux_wallets_le_peuvent(): void
    {
        $premier = $this->walletDe($this->client());
        $second = $this->walletDe($this->client());
        $empreinte = hash('sha256', 'exemple');

        $this->carte($premier, ['empreinte' => $empreinte]);
        $this->carte($second, ['empreinte' => $empreinte]);
        $this->carte($premier, ['empreinte' => null]);
        $this->carte($premier, ['empreinte' => null]);
        $this->assertSame(4, DB::table('cartes_virtuelles')->count());

        $this->expectException(QueryException::class);
        $this->carte($premier, ['empreinte' => $empreinte]);
    }

    public function test_deux_cartes_peuvent_partager_leurs_quatre_derniers_chiffres(): void
    {
        $wallet = $this->walletDe($this->client());

        $this->carte($wallet, ['empreinte' => hash('sha256', 'a')]);
        $this->carte($wallet, ['empreinte' => hash('sha256', 'b')]);

        $this->assertSame(2, DB::table('cartes_virtuelles')->where('wallet_id', $wallet)->where('numero_masque', '**** **** **** 4242')->count());
    }
}
