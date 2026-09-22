<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\Prestation;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaManager;
use App\Services\Media\StockageLocal;
use App\Services\Media\StockageSupabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreeDesPrestations;
use Tests\TestCase;

class MediaManagerTest extends TestCase
{
    use CreeDesPrestations, RefreshDatabase;

    private function gestionnaire(string $defaut = 'local'): MediaManager
    {
        return new MediaManager(
            new ImageProcessor(),
            ['local' => new StockageLocal(), 'supabase' => new StockageSupabase('https://projet.supabase.co', 'cle-secrete', 'medias')],
            $defaut,
        );
    }

    public function test_une_photo_est_ecrite_sur_le_disque_et_enregistree_en_base(): void
    {
        Storage::fake('medias_local');
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();

        $media = $this->gestionnaire()->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);

        Storage::disk('medias_local')->assertExists($media->chemin);
        $this->assertStringStartsWith("prestations/{$prestation->id}/", $media->chemin);
        $this->assertSame('local', $media->disk);
        $this->assertSame(1, $media->position);
        $this->assertSame(1, Media::query()->count());
    }

    public function test_les_positions_se_suivent(): void
    {
        Storage::fake('medias_local');
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();
        $gestionnaire = $this->gestionnaire();

        $premiere = $gestionnaire->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);
        $deuxieme = $gestionnaire->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);

        $this->assertSame([1, 2], [$premiere->position, $deuxieme->position]);
    }

    public function test_supprimer_efface_le_fichier_et_la_ligne(): void
    {
        Storage::fake('medias_local');
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();
        $gestionnaire = $this->gestionnaire();
        $media = $gestionnaire->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);

        $gestionnaire->supprimer($media);

        Storage::disk('medias_local')->assertMissing($media->chemin);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_un_nouvel_avatar_remplace_l_ancien(): void
    {
        Storage::fake('medias_local');
        $utilisateur = $this->unPrestataire();
        $gestionnaire = $this->gestionnaire();

        $ancien = $gestionnaire->remplacerAvatar($gestionnaire->preparer($this->uneImage()), $utilisateur);
        $nouveau = $gestionnaire->remplacerAvatar($gestionnaire->preparer($this->uneImage()), $utilisateur);

        Storage::disk('medias_local')->assertMissing($ancien->chemin);
        Storage::disk('medias_local')->assertExists($nouveau->chemin);
        $this->assertSame(1, Media::query()->where('type', Media::TYPE_AVATAR)->count());
    }

    public function test_supabase_recoit_le_bon_appel_http(): void
    {
        Http::fake(['*' => Http::response(['Key' => 'medias/x'], 200)]);
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();

        $media = $this->gestionnaire('supabase')->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);

        $this->assertSame('supabase', $media->disk);
        Http::assertSent(function (Request $requete) use ($media) {
            return $requete->method() === 'POST'
                && $requete->url() === 'https://projet.supabase.co/storage/v1/object/medias/'.$media->chemin
                && $requete->hasHeader('Authorization', 'Bearer cle-secrete')
                && $requete->hasHeader('apikey', 'cle-secrete')
                && $requete->hasHeader('x-upsert', 'true')
                && str_starts_with($requete->header('Content-Type')[0], 'image/')
                && strlen($requete->body()) > 100;
        });
        $this->assertSame('https://projet.supabase.co/storage/v1/object/public/medias/'.$media->chemin, $this->gestionnaire('supabase')->url($media));
    }

    public function test_si_supabase_refuse_rien_n_est_enregistre(): void
    {
        Http::fake(['*' => Http::response('Bucket not found', 404)]);
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();

        $image = $this->uneImage(); // hors du try : un éventuel « test sauté » (GD absent) ne doit pas être attrapé ci-dessous

        try {
            $this->gestionnaire('supabase')->ajouter($image, $prestation, Media::TYPE_PHOTO);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }

        $this->assertSame(0, Media::query()->count());
    }

    public function test_supprimer_sur_supabase_envoie_les_chemins_a_effacer(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();
        $media = new Media(['type' => Media::TYPE_PHOTO, 'disk' => 'supabase', 'chemin' => "prestations/{$prestation->id}/abc.webp", 'mime' => 'image/webp', 'taille_octets' => 10, 'position' => 1]);
        $media->mediable()->associate($prestation);
        $media->save();

        $this->gestionnaire('supabase')->supprimer($media);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://projet.supabase.co/storage/v1/object/medias'
            && $r->data() === ['prefixes' => ["prestations/{$prestation->id}/abc.webp"]]);
    }

    public function test_supabase_non_configure_donne_un_message_clair(): void
    {
        $gestionnaire = new MediaManager(new ImageProcessor(), ['local' => new StockageLocal(), 'supabase' => new StockageSupabase('', null)], 'supabase');
        $prestation = Prestation::factory()->for($this->unPrestataire(), 'prestataire')->create();

        $this->expectExceptionMessage('SUPABASE_SERVICE_KEY');

        $gestionnaire->ajouter($this->uneImage(), $prestation, Media::TYPE_PHOTO);
    }

    public function test_le_mode_de_stockage_inconnu_est_refuse_des_le_demarrage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gestionnaire('ftp');
    }
}
