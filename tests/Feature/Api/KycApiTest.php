<?php

namespace Tests\Feature\Api;

use App\Models\Categorie;
use App\Models\DemandeKyc;
use App\Models\DocumentKyc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** API mobile : vérification d'identité des prestataires (services, zones, 3 photos chiffrées). */
class KycApiTest extends TestCase
{
    use RefreshDatabase;

    private int $categorie;

    private int $ville;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['koudmain.api.kyc.stockage' => 'local']);
        $quartier = $this->creerQuartier();
        $this->ville = $quartier->ville_id;
        $this->categorie = Categorie::query()->create(['nom' => 'Beauté et Coiffure'])->id;
    }

    private function prestataire(bool $telephoneVerifie = true): User
    {
        $u = User::factory()->enAttente()->create(['quartier_id' => $this->creerQuartier()->id]);
        $u->forceFill(['telephone_verifie_at' => $telephoneVerifie ? now() : null])->save();

        return $u;
    }

    /** @return array<string, mixed> */
    private function envoi(array $surcharge = []): array
    {
        return $surcharge + [
            'categories' => [$this->categorie],
            'villes' => [$this->ville],
            'recto' => UploadedFile::fake()->image('recto.jpg', 800, 500),
            'verso' => UploadedFile::fake()->image('verso.jpg', 800, 500),
            'selfie' => UploadedFile::fake()->image('selfie.png', 600, 800),
        ];
    }

    public function test_un_prestataire_en_attente_sans_demande_doit_fournir_ses_documents(): void
    {
        Sanctum::actingAs($this->prestataire());

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.kyc', 'a_fournir');
        $this->getJson('/api/v1/prestataire/kyc')->assertOk()->assertJsonPath('data.statut', 'a_fournir')->assertJsonPath('data.documents', []);
    }

    public function test_envoi_complet_chiffre_les_photos_et_passe_en_attente(): void
    {
        $u = $this->prestataire();
        Sanctum::actingAs($u);

        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.kyc.statut', 'en_attente')
            ->assertJsonPath('data.kyc.categories', [$this->categorie])
            ->assertJsonPath('data.kyc.villes', [$this->ville])
            ->assertJsonPath('data.user.kyc', 'en_attente');

        $docs = DocumentKyc::query()->get();
        $this->assertCount(3, $docs);
        $this->assertEqualsCanonicalizing(['recto', 'verso', 'selfie'], $docs->pluck('type')->all());

        foreach ($docs as $doc) {
            $this->assertStringStartsWith('kyc/'.$u->id.'/', $doc->chemin);
            $chiffre = Storage::disk('local')->get($doc->chemin);
            $clair = Crypt::decryptString($chiffre);
            $this->assertStringNotContainsString('WEBP', substr($chiffre, 0, 64), 'le fichier stocké ne doit pas être lisible');
            $this->assertSame(hash('sha256', $clair), $doc->empreinte);
            $this->assertContains((new \finfo(FILEINFO_MIME_TYPE))->buffer($clair), ['image/webp', 'image/jpeg']);
        }

        // Rien dans le dossier public.
        $this->assertFileDoesNotExist(public_path('uploads/kyc'));
    }

    public function test_pas_de_second_envoi_pendant_la_verification(): void
    {
        Sanctum::actingAs($this->prestataire());
        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])->assertStatus(201);

        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'operation_refusee');
        $this->assertSame(1, DemandeKyc::query()->count());
    }

    public function test_apres_un_refus_on_peut_renvoyer_et_les_anciennes_photos_sont_effacees(): void
    {
        $u = $this->prestataire();
        Sanctum::actingAs($u);
        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])->assertStatus(201);
        $anciens = DocumentKyc::query()->pluck('chemin')->all();
        DemandeKyc::query()->update(['statut' => DemandeKyc::REFUSEE, 'motif_refus' => 'Photo floue.']);

        $this->getJson('/api/v1/prestataire/kyc')->assertJsonPath('data.statut', 'refusee')->assertJsonPath('data.motif_refus', 'Photo floue.');

        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertSame(3, DocumentKyc::query()->count());
        foreach ($anciens as $chemin) {
            Storage::disk('local')->assertMissing($chemin);
        }
    }

    public function test_messages_du_prototype_quand_il_manque_quelque_chose(): void
    {
        Sanctum::actingAs($this->prestataire());

        $this->post('/api/v1/prestataire/kyc', ['villes' => [$this->ville]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation')
            ->assertJsonPath('errors.categories.0', 'Choisissez au moins un service.')
            ->assertJsonPath('errors.selfie.0', 'Ajoutez votre selfie avec la pièce.');

        $this->assertSame(0, DemandeKyc::query()->count());
    }

    public function test_un_faux_fichier_image_est_refuse(): void
    {
        Sanctum::actingAs($this->prestataire());
        $faux = UploadedFile::fake()->createWithContent('recto.jpg', '<?php echo "pirate"; ?>');

        $this->post('/api/v1/prestataire/kyc', $this->envoi(['recto' => $faux]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'validation');
        $this->assertSame(0, DemandeKyc::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reserve_aux_prestataires_au_numero_verifie(): void
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $client->forceFill(['telephone_verifie_at' => now()])->save();
        Sanctum::actingAs($client);
        $this->getJson('/api/v1/prestataire/kyc')->assertStatus(403)->assertJsonPath('code', 'role_requis');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.kyc', null);

        Sanctum::actingAs($this->prestataire(telephoneVerifie: false));
        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])
            ->assertStatus(403)->assertJsonPath('code', 'telephone_non_verifie');
    }

    public function test_un_prestataire_deja_valide_est_verifie(): void
    {
        $u = User::factory()->prestataire()->create(['quartier_id' => $this->creerQuartier()->id]);
        $u->forceFill(['telephone_verifie_at' => now()])->save();
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.kyc', 'validee');
        $this->post('/api/v1/prestataire/kyc', $this->envoi(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'operation_refusee');
    }

    public function test_sans_jeton(): void
    {
        $this->getJson('/api/v1/prestataire/kyc')->assertStatus(401)->assertJsonPath('code', 'non_authentifie');
    }
}
