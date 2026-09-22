<?php

namespace Tests\Concerns;

use App\Models\Avis;
use App\Models\Categorie;
use App\Models\Commande;
use App\Models\Media;
use App\Models\Prestation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Petits constructeurs de données pour les tests du catalogue (aucun seeder : chaque test crée ce dont il a besoin). */
trait CreeDesPrestations
{
    protected function unService(string $nom = 'Coiffure femme', string $categorie = 'Beauté et Coiffure'): Service
    {
        return Categorie::firstOrCreate(['nom' => $categorie])->services()->firstOrCreate(['nom' => $nom]);
    }

    protected function unPrestataire(array $attributs = []): User
    {
        $this->creerQuartier();

        return User::factory()->prestataire()->create($attributs + ['quartier_id' => $this->creerQuartier()->id]);
    }

    protected function uneCommande(User $client, User $prestataire, Prestation $prestation): Commande
    {
        $commande = new Commande(['client_id' => $client->id, 'quartier_id' => $client->quartier_id, 'montant_total' => $prestation->prix]);
        $commande->forceFill(['prestataire_id' => $prestataire->id, 'statut' => 'terminee'])->save();
        $commande->prestations()->attach($prestation->id, ['prix_unitaire' => $prestation->prix, 'quantite' => 1]);

        return $commande;
    }

    /** Un avis réel (donc une commande terminée derrière), comme le lot 4 le produira. */
    protected function unAvis(Prestation $prestation, int $note, ?string $commentaire = null): Avis
    {
        $client = User::factory()->create(['quartier_id' => $this->creerQuartier()->id]);
        $commande = $this->uneCommande($client, $prestation->prestataire, $prestation);

        $avis = new Avis(['note' => $note, 'commentaire' => $commentaire]);
        $avis->forceFill(['commande_id' => $commande->id, 'prestation_id' => $prestation->id, 'user_id' => $client->id])->save();

        return $avis;
    }

    /** Ajoute une ligne « photo » sans passer par le traitement d'image (le fichier n'a pas besoin d'exister). */
    protected function unePhoto(Prestation $prestation, int $position = 0): Media
    {
        $media = new Media(['type' => Media::TYPE_PHOTO, 'disk' => 'local', 'chemin' => 'prestations/'.$prestation->id.'/'.uniqid().'.jpg', 'mime' => 'image/jpeg', 'taille_octets' => 1000, 'largeur' => 800, 'hauteur' => 600, 'position' => $position]);
        $media->mediable()->associate($prestation);
        $media->save();

        return $media;
    }

    /** Une vraie image JPEG (GD) pour les tests d'envoi. */
    protected function uneImage(string $nom = 'photo.jpg', int $largeur = 640, int $hauteur = 480): UploadedFile
    {
        // UploadedFile::fake()->image() dessine avec GD. Sans GD, on saute le test plutôt que de le faire échouer
        // pour une raison qui n'a rien à voir avec le code (php.ini : extension=gd).
        if (! extension_loaded('gd')) {
            $this->markTestSkipped("Extension PHP « gd » absente : activez extension=gd dans php.ini pour exécuter ce test.");
        }

        return UploadedFile::fake()->image($nom, $largeur, $hauteur);
    }
}
