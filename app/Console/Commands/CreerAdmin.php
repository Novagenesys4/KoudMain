<?php

namespace App\Console\Commands;

use App\Models\Quartier;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crée le compte administrateur. Remplace create_admin.php ET l'ancien admin@service.ci / "password"
 * du script SQL (plan, phase 0, étape 3) : aucun compte admin n'existe par défaut.
 *
 *   php artisan koudmain:creer-admin vous@exemple.ci
 *
 * Le mot de passe est demandé de façon masquée : il n'apparaît ni dans l'historique du terminal,
 * ni dans un fichier.
 */
class CreerAdmin extends Command
{
    protected $signature = 'koudmain:creer-admin
        {email : Adresse e-mail de l\'administrateur}
        {--prenom=Admin : Prénom}
        {--nom=KoudMain : Nom}
        {--telephone=0700000000 : Téléphone (format ivoirien)}
        {--quartier= : Identifiant du quartier (par défaut, le premier de la base)}';

    protected $description = 'Crée un compte administrateur (mot de passe demandé de façon masquée)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("« $email » n'est pas une adresse e-mail valide.");

            return self::FAILURE;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error("Un compte existe déjà avec l'adresse $email.");

            return self::FAILURE;
        }

        $quartierId = $this->option('quartier') ?: Quartier::query()->orderBy('id')->value('id');

        if (! $quartierId || ! Quartier::query()->whereKey($quartierId)->exists()) {
            $this->error('Aucun quartier en base. Chargez d\'abord la géographie : php artisan db:seed');

            return self::FAILURE;
        }

        $motDePasse = (string) $this->secret('Mot de passe (8 caractères minimum, avec une lettre et un chiffre)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        if ($motDePasse !== $confirmation) {
            $this->error('Les deux mots de passe ne sont pas identiques.');

            return self::FAILURE;
        }

        $validation = Validator::make(['password' => $motDePasse], [
            'password' => ['required', 'string', 'max:72', Password::min(8)->letters()->numbers()],
        ]);

        if ($validation->fails()) {
            $this->error($validation->errors()->first('password'));

            return self::FAILURE;
        }

        $admin = DB::transaction(function () use ($email, $motDePasse, $quartierId): User {
            $admin = new User([
                'nom' => $this->option('nom'),
                'prenom' => $this->option('prenom'),
                'email' => $email,
                'telephone' => $this->option('telephone'),
                'password' => $motDePasse,
                'quartier_id' => $quartierId,
            ]);

            $admin->forceFill([
                'est_client' => false,
                'est_prestataire' => false,
                'est_admin' => true,
                'est_valide' => true,
                // Créé en ligne de commande par le propriétaire du serveur : l'adresse est réputée confirmée (règle 19).
                'email_verified_at' => now(),
            ])->save();

            $admin->wallet()->create();

            return $admin;
        });

        $this->info("Administrateur créé : {$admin->email} (identifiant {$admin->id}).");

        return self::SUCCESS;
    }
}
