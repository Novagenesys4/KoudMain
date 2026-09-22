<?php

namespace Tests\Feature\Media;

use App\Services\Media\ImageInvalide;
use App\Services\Media\ImageProcessor;
use PHPUnit\Framework\TestCase;

/** Le traitement d'image n'a besoin ni de la base ni de Laravel : GD suffit. */
class ImageProcessorTest extends TestCase
{
    private array $fichiers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Extension PHP « gd » absente : activez extension=gd dans php.ini pour exécuter ce test.');
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->fichiers, 'is_file'));
        parent::tearDown();
    }

    private function fichier(string $contenu, string $suffixe = '.jpg'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'img').$suffixe;
        file_put_contents($chemin, $contenu);

        return $this->fichiers[] = $chemin;
    }

    private function jpeg(int $largeur, int $hauteur): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefilledrectangle($image, 0, 0, $largeur, $hauteur, imagecolorallocate($image, 200, 120, 60));
        ob_start();
        imagejpeg($image);

        return $this->fichier((string) ob_get_clean());
    }

    public function test_une_image_valide_est_redessinee_et_compressee(): void
    {
        $resultat = (new ImageProcessor())->traiter($this->jpeg(800, 600));

        $this->assertContains($resultat->mime, ['image/webp', 'image/jpeg']);
        $this->assertSame(800, $resultat->largeur);
        $this->assertSame(600, $resultat->hauteur);
        $this->assertGreaterThan(0, $resultat->taille());
    }

    public function test_une_grande_image_est_reduite_a_la_largeur_maximale(): void
    {
        $resultat = (new ImageProcessor(largeurMax: 1000))->traiter($this->jpeg(3000, 2000));

        $this->assertSame(1000, $resultat->largeur);
        $this->assertSame(667, $resultat->hauteur); // le rapport largeur / hauteur est conservé
    }

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse_meme_avec_une_extension_jpg(): void
    {
        $this->expectException(ImageInvalide::class);
        $this->expectExceptionMessage('Format non supporté');

        (new ImageProcessor())->traiter($this->fichier('<?php echo "pirate"; ?>'));
    }

    public function test_un_gif_est_refuse(): void
    {
        $this->expectException(ImageInvalide::class);

        (new ImageProcessor())->traiter($this->fichier(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), '.gif'));
    }

    public function test_une_image_trop_petite_est_refusee(): void
    {
        $this->expectException(ImageInvalide::class);
        $this->expectExceptionMessage('trop petite');

        (new ImageProcessor())->traiter($this->jpeg(100, 80));
    }

    public function test_une_bombe_de_decompression_est_refusee_avant_d_etre_decodee(): void
    {
        $this->expectException(ImageInvalide::class);

        // 4000 x 3000 = 12 millions de pixels > limite fixée à 1 million.
        (new ImageProcessor(pixelsMax: 1_000_000))->traiter($this->jpeg(4000, 3000));
    }

    public function test_un_fichier_absent_est_refuse_proprement(): void
    {
        $this->expectException(ImageInvalide::class);

        (new ImageProcessor())->traiter('/tmp/n-existe-pas.jpg');
    }
}
