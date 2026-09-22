<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Règles 11 et 12 : taille maximale des envois, et type réel du fichier (contenu, pas extension ni type annoncé). */
class UploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Extension gd absente.');
        }

        Storage::fake('medias_local');
        $this->client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
    }

    private function jpeg(int $l = 400, int $h = 300): string
    {
        $image = imagecreatetruecolor($l, $h);
        imagefilledrectangle($image, 0, 0, $l, $h, imagecolorallocate($image, 200, 120, 60));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function envoyer(UploadedFile $fichier): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->client)->from('/compte/profil')->post('/compte/avatar', ['avatar' => $fichier]);
    }

    private function fichier(string $nom, string $contenu, ?string $mime = null): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, $mime, null, true);
    }

    public function test_un_script_php_renomme_en_jpg_est_refuse(): void
    {
        $this->envoyer($this->fichier('photo.jpg', "<?php system(\$_GET['c']); ?>", 'image/jpeg'))->assertSessionHasErrors('avatar');

        $this->assertSame([], Storage::disk('medias_local')->allFiles());
    }

    public function test_un_svg_avec_javascript_est_refuse_meme_annonce_comme_png(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $this->envoyer($this->fichier('logo.png', $svg, 'image/png'))->assertSessionHasErrors('avatar');
        $this->envoyer($this->fichier('logo.svg', $svg, 'image/svg+xml'))->assertSessionHasErrors('avatar');

        $this->assertSame([], Storage::disk('medias_local')->allFiles());
    }

    public function test_un_gif_et_un_pdf_sont_refuses(): void
    {
        $this->envoyer($this->fichier('a.jpg', 'GIF89a'.str_repeat("\0", 300), 'image/jpeg'))->assertSessionHasErrors('avatar');
        $this->envoyer($this->fichier('a.jpg', "%PDF-1.4\n%".str_repeat('x', 300), 'image/jpeg'))->assertSessionHasErrors('avatar');
    }

    public function test_un_fichier_php_glisse_apres_une_vraie_image_ne_survit_pas(): void
    {
        // « Polyglotte » : un vrai JPEG suivi de code PHP. L'image est redessinée : seuls les pixels sont conservés.
        $piege = $this->jpeg().'<?php echo "PWNED"; system($_GET["c"]); ?>';

        $this->envoyer($this->fichier('photo.jpg', $piege, 'image/jpeg'))->assertSessionHasNoErrors();

        $fichiers = Storage::disk('medias_local')->allFiles();
        $this->assertCount(1, $fichiers);

        foreach ($fichiers as $chemin) {
            $contenu = Storage::disk('medias_local')->get($chemin);
            $this->assertStringNotContainsString('<?php', $contenu);
            $this->assertStringNotContainsString('PWNED', $contenu);
            $this->assertMatchesRegularExpression('/\.(webp|jpg)$/', $chemin); // jamais l'extension du fichier envoyé
        }
    }

    public function test_les_metadonnees_exif_ne_sont_pas_conservees(): void
    {
        $exif = $this->jpeg().'Exif-GPS-48.8566N-2.3522E';

        $this->envoyer($this->fichier('photo.jpg', $exif, 'image/jpeg'))->assertSessionHasNoErrors();

        foreach (Storage::disk('medias_local')->allFiles() as $chemin) {
            $this->assertStringNotContainsString('Exif-GPS', Storage::disk('medias_local')->get($chemin));
        }
    }

    public function test_une_image_de_plus_de_5_mo_est_refusee(): void
    {
        $gros = UploadedFile::fake()->create('grosse.jpg', 5121, 'image/jpeg'); // 5 Mo + 1 Ko

        $this->envoyer($gros)->assertSessionHasErrors('avatar');
        $this->assertSame([], Storage::disk('medias_local')->allFiles());
    }

    public function test_plusieurs_fichiers_ou_un_tableau_ne_plantent_pas_l_envoi_d_avatar(): void
    {
        $this->actingAs($this->client)->from('/compte/profil')
            ->post('/compte/avatar', ['avatar' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
            ->assertSessionHasErrors('avatar');
    }

    public function test_les_limites_du_serveur_sont_en_place(): void
    {
        $ini = file_get_contents(base_path('docker/php/koudmain.ini'));
        $apache = file_get_contents(base_path('docker/apache/koudmain.conf'));

        // PHP : taille d'un fichier, taille totale d'une requête, nombre de fichiers.
        $this->assertMatchesRegularExpression('/^upload_max_filesize\s*=\s*\d+M/m', $ini);
        $this->assertMatchesRegularExpression('/^post_max_size\s*=\s*\d+M/m', $ini);
        $this->assertMatchesRegularExpression('/^max_file_uploads\s*=\s*\d+/m', $ini);

        // Apache : refuse (413) une requête gigantesque avant même de la donner à PHP ; aucun script exécuté dans le dossier des envois.
        $this->assertMatchesRegularExpression('/^\s*LimitRequestBody\s+\d+/m', $apache);
        $this->assertStringContainsString('uploads', $apache);
        $this->assertMatchesRegularExpression('/\\\\\.\(php/i', $apache);
    }

    public function test_la_limite_de_poids_de_la_config_ne_depasse_pas_celle_de_php(): void
    {
        $ini = file_get_contents(base_path('docker/php/koudmain.ini'));
        preg_match('/^upload_max_filesize\s*=\s*(\d+)M/m', $ini, $m);

        $this->assertLessThanOrEqual((int) $m[1] * 1024, (int) config('koudmain.media.envoi_max_ko') + 1024);
    }
}
