<?php

namespace App\Services\Media;

use finfo;
use GdImage;

/**
 * Nettoie et compresse une image envoyée par un utilisateur (reprend l'ancien image_service.php).
 *
 * Pourquoi ne jamais stocker le fichier tel quel :
 *  - le VRAI type est lu dans le contenu du fichier (finfo), pas dans son extension ni dans le type annoncé
 *    par le navigateur, que l'on peut falsifier ;
 *  - l'image est REDESSINÉE pixel par pixel avec GD : tout contenu caché (du code PHP ou JavaScript glissé
 *    dans le fichier) et toutes les métadonnées EXIF (position GPS du téléphone !) disparaissent ;
 *  - elle est réduite (1600 px de large au plus) et compressée en WebP (ou JPEG si WebP est absent) :
 *    60 à 200 Ko au lieu de plusieurs Mo, ce qui compte beaucoup sur une connexion mobile ;
 *  - la taille en pixels est limitée : une image de 100 000 x 100 000 pixels, minuscule sur disque, ferait
 *    exploser la mémoire du serveur une fois décompressée (« bombe de décompression »).
 */
final class ImageProcessor
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly int $largeurMax = 1600,
        private readonly int $qualite = 82,
        private readonly int $pixelsMax = 30_000_000,
        private readonly int $largeurMin = 200,
        private readonly int $hauteurMin = 150,
        private readonly int $sortieMaxOctets = 3 * 1024 * 1024,
    ) {
    }

    /**
     * @throws ImageInvalide si l'image est refusée (message destiné à l'utilisateur)
     */
    public function traiter(string $chemin): ImageTraitee
    {
        if (! is_file($chemin) || ! is_readable($chemin)) {
            throw new ImageInvalide("L'envoi de l'image a échoué. Réessayez.");
        }

        if (! extension_loaded('gd')) {
            throw new ImageInvalide("Le traitement des images n'est pas disponible sur ce serveur.");
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($chemin);
        if (! in_array($mime, self::MIMES, true)) {
            throw new ImageInvalide('Format non supporté : JPG, PNG ou WebP uniquement.');
        }

        $infos = @getimagesize($chemin);
        if ($infos === false || $infos[0] < 1 || $infos[1] < 1) {
            throw new ImageInvalide("Image illisible : essayez une autre photo.");
        }

        [$largeur, $hauteur] = $infos;

        if ($largeur * $hauteur > $this->pixelsMax) {
            $mega = (int) ($this->pixelsMax / 1_000_000);
            throw new ImageInvalide("Image trop grande ($mega mégapixels maximum). Réduisez-la puis réessayez.");
        }

        if ($largeur < $this->largeurMin || $hauteur < $this->hauteurMin) {
            throw new ImageInvalide("Image trop petite ({$this->largeurMin} × {$this->hauteurMin} pixels minimum).");
        }

        // Une image décompressée occupe environ 4 octets par pixel, et il en faut deux en même temps.
        $this->assurerMemoire((int) ($largeur * $hauteur * 5.5));

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($chemin),
            'image/png' => @imagecreatefrompng($chemin),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($chemin) : false,
        };

        if (! $source instanceof GdImage) {
            throw new ImageInvalide("Cette image n'a pas pu être lue. Essayez une autre photo.");
        }

        // Photos de téléphone : on applique la rotation demandée par l'EXIF AVANT de supprimer l'EXIF.
        if ($mime === 'image/jpeg') {
            $source = $this->redresser($source, $chemin);
            $largeur = imagesx($source);
            $hauteur = imagesy($source);
        }

        // Nouvelle toile : aucune donnée de l'original n'y survit.
        $nouvelleLargeur = min($largeur, $this->largeurMax);
        $nouvelleHauteur = max(1, (int) round($hauteur * $nouvelleLargeur / $largeur));
        $toile = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);

        // Fond blanc sous les zones transparentes : sans lui, la transparence d'un PNG deviendrait noire en JPEG.
        imagefill($toile, 0, 0, imagecolorallocate($toile, 255, 255, 255));
        imagecopyresampled($toile, $source, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);
        unset($source);

        if (function_exists('imagewebp')) {
            $mimeSortie = 'image/webp';
            $extension = 'webp';
            $binaire = $this->encoder(fn () => imagewebp($toile, null, $this->qualite));
        } else {
            $mimeSortie = 'image/jpeg';
            $extension = 'jpg';
            imageinterlace($toile, true); // JPEG progressif : s'affiche petit à petit en 3G
            $binaire = $this->encoder(fn () => imagejpeg($toile, null, $this->qualite));
        }

        unset($toile);

        if ($binaire === '' || strlen($binaire) > $this->sortieMaxOctets) {
            throw new ImageInvalide("Cette image n'a pas pu être traitée. Essayez une autre photo.");
        }

        return new ImageTraitee($binaire, $mimeSortie, $extension, $nouvelleLargeur, $nouvelleHauteur);
    }

    private function encoder(callable $ecrire): string
    {
        ob_start();
        $ok = $ecrire();
        $binaire = (string) ob_get_clean();

        return $ok ? $binaire : '';
    }

    private function redresser(GdImage $image, string $chemin): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($chemin);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $tournee = imagerotate($image, $angle, 0);

        return $tournee instanceof GdImage ? $tournee : $image;
    }

    /** Vérifie (et au besoin augmente) la mémoire disponible avant de décompresser une grande image. */
    private function assurerMemoire(int $octets): void
    {
        $limite = self::enOctets((string) ini_get('memory_limit'));

        if ($limite < 0) {
            return; // illimitée
        }

        $besoin = memory_get_usage(true) + $octets;

        if ($besoin <= $limite) {
            return;
        }

        // Plafond raisonnable : 768 Mo. Au-delà, on refuse plutôt que de risquer de faire tomber le site.
        if ($besoin <= 768 * 1024 * 1024 && @ini_set('memory_limit', (string) ($besoin + 32 * 1024 * 1024)) !== false) {
            return;
        }

        throw new ImageInvalide('Image trop grande pour être traitée. Réduisez-la puis réessayez.');
    }

    private static function enOctets(string $valeur): int
    {
        $valeur = trim($valeur);

        if ($valeur === '' || $valeur === '-1') {
            return -1;
        }

        $nombre = (int) $valeur;

        return match (strtolower(substr($valeur, -1))) {
            'g' => $nombre * 1024 * 1024 * 1024,
            'm' => $nombre * 1024 * 1024,
            'k' => $nombre * 1024,
            default => $nombre,
        };
    }
}
