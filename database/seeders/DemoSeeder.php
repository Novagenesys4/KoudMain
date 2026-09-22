<?php

namespace Database\Seeders;

use App\Models\Avis;
use App\Models\Commande;
use App\Models\Media;
use App\Models\Prestation;
use App\Models\Quartier;
use App\Enums\ActionCommande;
use App\Enums\ModePaiement;
use App\Models\Service;
use App\Models\User;
use App\Services\CarteVirtuelleService;
use App\Services\CommandeService;
use App\Services\DisponibiliteService;
use App\Services\Media\MediaManager;
use App\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Données de DÉMONSTRATION pour développer et tester le catalogue en local :
 *     php artisan db:seed --class=DemoSeeder
 * Des prestataires fictifs (mot de passe « password »), des prestations, des photos générées et quelques avis.
 * Refuse de tourner en production : ces comptes n'ont rien à y faire.
 */
class DemoSeeder extends Seeder
{
    public function run(MediaManager $medias): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder crée des comptes fictifs : il ne doit jamais tourner en production.');
        }

        if (Service::query()->doesntExist() || Quartier::query()->doesntExist()) {
            $this->call([GeographieSeeder::class, CategorieServiceSeeder::class]);
        }

        // Les photos de démonstration sont dessinées avec l'extension GD de PHP. Sans elle, on crée tout le reste
        // (comptes, prestations, avis) et on le dit clairement, au lieu de planter au milieu du travail.
        $avecGd = extension_loaded('gd');

        if (! $avecGd) {
            $this->command?->warn("L'extension PHP « gd » n'est pas activée : les prestations sont créées SANS photos.");
            $this->command?->warn('Activez-la (php.ini : extension=gd), puis relancez cette commande : elle ajoutera les photos manquantes.');
        }

        $quartiers = Quartier::query()->orderBy('id')->limit(12)->pluck('id')->all();
        $client = $this->utilisateur('demo.client@koudmain.test', 'Awa', 'Koné', $quartiers[0], client: true);

        // [prénom, nom, e-mail, bio, [[titre, service, prix, durée, description, teinte, avis...]]]
        $prestataires = [
            ['Mariam', 'Traoré', 'demo.mariam@koudmain.test', 'Coiffeuse depuis 9 ans à Cocody. Tresses, tissages et coiffures de mariage, à domicile ou dans mon salon.', [
                ['Tresses africaines à domicile', 'Coiffure femme', 15000, 180, "Tresses nattées, vanilles ou box braids, faites chez vous.\nMèches non incluses ; je peux vous conseiller sur le choix.", [214, 122, 92], [[5, 'Travail soigné, très ponctuelle. Je recommande !'], [5, null], [4, 'Un peu de retard mais le résultat est superbe.']]],
                ['Coiffure de mariage', 'Coiffure femme', 45000, 240, 'Essai inclus la semaine précédente.', [180, 96, 120], [[5, 'Magnifique, tout le monde a complimenté ma coiffure.']]],
            ]],
            ['Ibrahim', 'Diallo', 'demo.ibrahim@koudmain.test', 'Plombier agréé, 12 ans d’expérience. Intervention rapide sur Abidjan.', [
                ['Réparation de fuite d’eau', 'Réparation fuite', 12000, 90, 'Recherche et réparation de fuites sous évier, robinet ou chasse d’eau. Déplacement compris.', [40, 110, 150], [[4, 'Rapide et efficace.'], [5, null]]],
                ['Installation de lavabo et WC', 'Installation sanitaire', 35000, 300, null, [70, 130, 160], []],
            ]],
            ['Fatoumata', 'Sanogo', 'demo.fatoumata@koudmain.test', 'Pressing et repassage de quartier, retrait et livraison possibles.', [
                ['Lavage et repassage (5 kg)', 'Lavage vêtements', 8000, null, 'Lavage, séchage, repassage. Rendu sous 48 h.', [30, 150, 140], [[5, 'Vêtements impeccables.'], [4, null], [4, null]]],
            ]],
            ['Kouadio', 'Yao', 'demo.kouadio@koudmain.test', 'Baby-sitter expérimenté, formation aux premiers secours.', [
                ['Garde d’enfants à domicile', 'Garde à domicile', 3000, 60, 'Tarif à l’heure. Jeux, aide aux devoirs, repas.', [260, 120, 130], []],
            ]],
            ['Aya', 'Bamba', 'demo.aya@koudmain.test', 'Cuisinière traiteur : ivoirienne et cuisine du monde.', [
                ['Repas de fête pour 20 personnes', 'Traiteur événement', 120000, 480, 'Menu sur mesure : attiéké-poisson, alloco, kédjenou…', [20, 140, 60], [[5, 'Délicieux, généreux.']]],
            ]],
        ];

        foreach ($prestataires as $i => [$prenom, $nom, $email, $bio, $liste]) {
            $prestataire = $this->utilisateur($email, $prenom, $nom, $quartiers[($i + 1) % count($quartiers)], client: false, bio: $bio);

            foreach ($liste as $rang => [$titre, $service, $prix, $duree, $description, $couleur, $avis]) {
                // Relancer le seeder complète ce qui manque (photos, avis) sans jamais créer de doublon.
                $prestation = Prestation::query()->where('prestataire_id', $prestataire->id)->where('titre', $titre)->first()
                    ?? $prestataire->prestations()->create([
                        'service_id' => Service::query()->where('nom', $service)->value('id'),
                        'titre' => $titre,
                        'prix' => $prix,
                        'duree_minutes' => $duree,
                        'description' => $description,
                        'est_active' => true,
                    ]);

                if ($avecGd && $prestation->medias()->doesntExist()) {
                    foreach ([0, 1, 2] as $n) {
                        $chemin = $this->imageDemo(($i * 2 + $rang + $n) % count(self::TONS), $n);
                        $medias->enregistrer($medias->preparer($chemin), $prestation, Media::TYPE_PHOTO);
                        @unlink($chemin);
                    }
                }

                if ($prestation->avis()->doesntExist()) {
                    foreach ($avis as [$note, $commentaire]) {
                        $this->avis($prestation, $client, $note, $commentaire);
                    }
                }
            }
        }

        $this->activite($client);
    }

    /**
     * Des commandes à tous les stades, créées PAR LES VRAIS SERVICES (donc avec de vrais séquestres et un wallet cohérent) :
     * de quoi voir les espaces client, prestataire et administrateur remplis. Une seule fois (relancer ne duplique rien).
     */
    private function activite(User $client): void
    {
        if (Commande::query()->whereNotNull('date_souhaitee')->exists()) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($client): void {
            $this->commandesDeDemonstration($client);
        });
    }

    private function commandesDeDemonstration(User $client): void
    {
        $wallets = app(WalletService::class);
        $commandes = app(CommandeService::class);

        // Seul ce client de démonstration a des cartes (un vrai nouvel utilisateur n'en a aucune) : des numéros de TEST que les
        // banques publient (Visa 4242…, Mastercard 5555…, American Express 3782…). Ils passent la clé de Luhn, mais ne paient rien.
        $cartes = app(CarteVirtuelleService::class);
        $identite = ['prenom' => 'Aïcha', 'nom' => 'Koné', 'adresse' => 'Rue des Jardins, Riviera 2', 'ville' => 'Abidjan', 'pays' => "Côte d'Ivoire"];
        $expiration = now()->addYears(3)->format('m/y');
        $principale = $cartes->ajouter($client, $identite + ['numero' => '4242 4242 4242 4242', 'expiration' => $expiration, 'cvv' => '123', 'libelle' => 'Visa personnelle', 'couleur' => 'emerald']);
        $saphir = $cartes->ajouter($client, $identite + ['numero' => '5555 5555 5555 4444', 'expiration' => $expiration, 'cvv' => '456', 'libelle' => 'Courses & services', 'couleur' => 'saphir']);
        $cartes->ajouter($client, $identite + ['numero' => '3782 822463 10005', 'expiration' => $expiration, 'cvv' => '7890', 'libelle' => 'American Express', 'couleur' => 'platinum']);
        $wallets->mouvement($client, 'credit', 250000, 'Recharge via Wave (démonstration)', null);
        $wallets->mouvement($client, 'credit', 120000, 'Recharge par carte (démonstration)', null, $saphir->id);

        $offre = fn (string $email, string $titre) => Prestation::query()->where('titre', $titre)
            ->whereHas('prestataire', fn ($q) => $q->where('email', $email))->firstOrFail();
        $prestataire = fn (string $email) => User::query()->where('email', $email)->firstOrFail();

        // Horaires de deux prestataires (les autres gardent la plage par défaut).
        $horaires = app(DisponibiliteService::class);
        $horaires->enregistrer($prestataire('demo.mariam@koudmain.test'), [
            1 => [['08:00', '12:00'], ['14:00', '18:00']], 2 => [['08:00', '12:00'], ['14:00', '18:00']], 3 => [['08:00', '12:00'], ['14:00', '18:00']],
            4 => [['08:00', '12:00'], ['14:00', '18:00']], 5 => [['08:00', '12:00'], ['14:00', '18:00']], 6 => [['09:00', '13:00']],
        ]);
        $horaires->enregistrer($prestataire('demo.ibrahim@koudmain.test'), array_fill_keys([1, 2, 3, 4, 5], [['07:00', '19:00']]));

        // Prochain jour ouvré (lundi à vendredi) à partir de dans N jours, à l'heure donnée.
        $jour = function (int $dans, string $heure): CarbonImmutable {
            $date = CarbonImmutable::now()->addDays($dans);

            while ($date->isWeekend()) {
                $date = $date->addDay();
            }

            return $date->setTimeFromTimeString($heure);
        };

        $mariam = $prestataire('demo.mariam@koudmain.test');
        $ibrahim = $prestataire('demo.ibrahim@koudmain.test');
        $fatoumata = $prestataire('demo.fatoumata@koudmain.test');
        $kouadio = $prestataire('demo.kouadio@koudmain.test');
        $aya = $prestataire('demo.aya@koudmain.test');

        // 1. Une demande en attente de réponse.
        $commandes->commander($client, $offre($mariam->email, 'Tresses africaines à domicile'), 1, $jour(2, '08:00'), 'Portail bleu, 2e étage. Merci de venir avec les mèches.', null, null, ModePaiement::Physique);

        // 2. Acceptée : rendez-vous pris.
        $acceptee = $commandes->commander($client, $offre($mariam->email, 'Coiffure de mariage'), 1, $jour(4, '14:00'), null, null, $saphir->id, ModePaiement::Carte);
        $commandes->agir($acceptee, $mariam, ActionCommande::Accepter);

        // 3. Terminée, en attente de la confirmation du client (le paiement est encore en séquestre).
        $terminee = $commandes->commander($client, $offre($ibrahim->email, 'Réparation de fuite d’eau'), 1, $jour(1, '08:00'));
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $action) {
            $commandes->agir($terminee, $ibrahim, $action);
        }

        // 4. Réglée : le prestataire est payé, il a déjà demandé un retrait.
        $reglee = $commandes->commander($client, $offre($fatoumata->email, 'Lavage et repassage (5 kg)'), 2, $jour(1, '15:00'), null, null, $principale->id, ModePaiement::Carte);
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer, ActionCommande::Terminer] as $action) {
            $commandes->agir($reglee, $fatoumata, $action);
        }
        $commandes->agir($reglee, $client, ActionCommande::ConfirmerReception);
        $wallets->demanderRetrait($fatoumata, 5000, 'Orange Money', '0708091011');

        // 5. Un litige à arbitrer.
        $litige = $commandes->commander($client, $offre($kouadio->email, 'Garde d’enfants à domicile'), 3, $jour(2, '16:00'), null, null, null, ModePaiement::MobileMoney);
        foreach ([ActionCommande::Accepter, ActionCommande::Demarrer] as $action) {
            $commandes->agir($litige, $kouadio, $action);
        }
        $commandes->agir($litige, $client, ActionCommande::OuvrirLitige, 'Le baby-sitter est parti après une heure au lieu de trois.');

        // 6. Annulée par le client : remboursée.
        $annulee = $commandes->commander($client, $offre($aya->email, 'Repas de fête pour 20 personnes'), 1, $jour(6, '11:00'));
        $commandes->agir($annulee, $client, ActionCommande::Annuler, 'La fête est reportée.');
    }

    private function utilisateur(string $email, string $prenom, string $nom, int $quartierId, bool $client, ?string $bio = null): User
    {
        $utilisateur = User::query()->firstOrNew(['email' => $email]);
        $utilisateur->forceFill([
            'prenom' => $prenom,
            'nom' => $nom,
            'telephone' => '07'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'quartier_id' => $quartierId,
            'est_client' => $client,
            'est_prestataire' => ! $client,
            'est_admin' => false,
            'est_valide' => true,
            'email_verified_at' => now(),
            'bio' => $bio,
        ])->save();

        return $utilisateur;
    }

    private function avis(Prestation $prestation, User $client, int $note, ?string $commentaire): void
    {
        $commande = new Commande(['client_id' => $client->id, 'quartier_id' => $client->quartier_id, 'montant_total' => $prestation->prix]);
        $commande->forceFill(['prestataire_id' => $prestation->prestataire_id, 'statut' => 'terminee', 'terminee_at' => now()->subDays(random_int(2, 60))])->save();
        $commande->prestations()->attach($prestation->id, ['prix_unitaire' => $prestation->prix, 'quantite' => 1]);

        $avis = new Avis(['note' => $note, 'commentaire' => $commentaire]);
        $avis->forceFill(['commande_id' => $commande->id, 'prestation_id' => $prestation->id, 'user_id' => $client->id, 'created_at' => $commande->terminee_at->addDay()])->save();
    }

    /** Les tons des photos de démonstration : ceux de la marque (terracotta, sauge, or, prune, blush, encre). */
    private const TONS = [
        [[240, 214, 196], [196, 118, 82]],
        [[204, 224, 212], [90, 138, 118]],
        [[244, 228, 184], [196, 148, 66]],
        [[230, 210, 216], [136, 100, 110]],
        [[246, 226, 214], [218, 160, 132]],
        [[112, 104, 92], [40, 38, 33]],
    ];

    /**
     * Une « photo » de démonstration dessinée avec GD (dégradé chaud, cercle et arche translucides) : de quoi voir l'interface
     * avec des images aux couleurs de la marque, sans télécharger la moindre photo. $ton choisit la teinte, $variante la composition.
     */
    private function imageDemo(int $ton, int $variante): string
    {
        $largeur = 1200;
        $hauteur = 900;
        $image = imagecreatetruecolor($largeur, $hauteur);
        [$haut, $bas] = self::TONS[$ton % count(self::TONS)];

        for ($y = 0; $y < $hauteur; $y++) {
            $t = $y / $hauteur;
            $ligne = imagecolorallocate(
                $image,
                (int) ($haut[0] + ($bas[0] - $haut[0]) * $t),
                (int) ($haut[1] + ($bas[1] - $haut[1]) * $t),
                (int) ($haut[2] + ($bas[2] - $haut[2]) * $t),
            );
            imageline($image, 0, $y, $largeur, $y, $ligne);
        }

        $clair = imagecolorallocatealpha($image, 255, 253, 250, 96);
        $tres = imagecolorallocatealpha($image, 255, 253, 250, 112);
        $ombre = imagecolorallocatealpha($image, 38, 36, 31, 108);

        // Grand disque, petit disque et arche : la composition change avec la variante.
        imagefilledellipse($image, 340 + $variante * 300, 400, 620, 620, $clair);
        imagefilledellipse($image, 900 - $variante * 140, 640, 300, 300, $tres);
        imagefilledellipse($image, 860 - $variante * 200, 250, 180, 180, $ombre);
        imagefilledrectangle($image, 0, 760, $largeur, $hauteur, $ombre);

        $chemin = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($image, $chemin, 88);
        unset($image);

        return $chemin;
    }
}
