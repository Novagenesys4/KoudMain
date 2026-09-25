<?php

namespace App\Models;

use App\Services\OtpService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // HasApiTokens : jetons de connexion de l'application mobile (Sanctum). Le site web, lui, reste en session.
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Champs remplissables en masse (User::create([...]), $user->update([...])).
     *
     * Les rôles (est_admin, est_prestataire, est_valide...) n'y figurent VOLONTAIREMENT pas :
     * sinon un utilisateur pourrait s'auto-promouvoir admin en ajoutant un champ caché
     * dans un formulaire ("mass assignment"). On les modifie explicitement, côté serveur.
     */
    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'password',
        'quartier_id',
        'bio',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'telephone_verifie_at' => 'datetime',
            'password' => 'hashed', // hache automatiquement à l'affectation
            'est_client' => 'boolean',
            'est_prestataire' => 'boolean',
            'est_admin' => 'boolean',
            'est_valide' => 'boolean',
            'notifications_email' => 'boolean',
        ];
    }

    /** Accessible via $user->nom_complet */
    protected function nomComplet(): Attribute
    {
        return Attribute::get(fn () => trim($this->prenom.' '.$this->nom));
    }

    /** L'e-mail est toujours enregistré en minuscules (l'unicité ne dépend pas de la casse). */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $valeur) => mb_strtolower(trim($valeur)));
    }

    // ---------------------------------------------------------------- Rôles

    /** Prestataire inscrit dont le profil n'a pas encore été validé par un administrateur. */
    public function enAttenteValidation(): bool
    {
        return $this->est_prestataire && ! $this->est_valide && ! $this->est_admin;
    }

    /** Numéro de téléphone confirmé par un code SMS (application mobile). */
    public function telephoneVerifie(): bool
    {
        return $this->telephone_verifie_at !== null;
    }

    /** Adresse e-mail confirmée par le lien reçu : le compte est « certifié » (badge). */
    public function emailConfirme(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Règle d'accès : un compte s'utilise dès qu'il a AU MOINS un identifiant vérifié, le numéro (code SMS, application)
     * ou l'adresse e-mail (lien, site). La confirmation de l'e-mail n'est plus obligatoire : elle certifie le compte.
     */
    public function aUnIdentifiantVerifie(): bool
    {
        return $this->telephoneVerifie() || $this->emailConfirme();
    }

    /**
     * Le compte peut-il commander, agir sur une commande, recharger ou retirer (middleware api.telephone) ?
     *  - codes par SMS : le numéro doit être vérifié ;
     *  - codes par e-mail (SMS_DRIVER=email, en attendant un fournisseur SMS) : le code reçu prouve l'adresse e-mail, qui suffit.
     */
    public function compteVerifie(): bool
    {
        return $this->telephoneVerifie() || (OtpService::parEmail() && $this->emailConfirme());
    }

    /**
     * L'identifiant saisi à la connexion (e-mail ou numéro) est-il vérifié ? On ne se connecte qu'avec un identifiant vérifié.
     * Codes par e-mail : le numéro sert aussi d'identifiant dès que le compte est vérifié par son adresse (le mot de passe
     * reste exigé, et ConnexionApiRequest::trouver refuse un numéro partagé par plusieurs comptes).
     */
    public function identifiantVerifie(string $login): bool
    {
        return str_contains($login, '@') ? $this->emailConfirme() : $this->compteVerifie();
    }

    /** Sert au middleware "role:..." (App\Http\Middleware\EnsureRole). */
    public function aLeRole(string $role): bool
    {
        return match ($role) {
            'admin' => $this->est_admin,
            'prestataire' => $this->est_prestataire && $this->est_valide,
            'client' => $this->est_client,
            default => false,
        };
    }

    /**
     * L'espace par défaut de cet utilisateur : « admin », « prestataire » ou « client » (l'administrateur passe en premier).
     * Un compte client qui a ouvert un espace prestataire (application mobile) reste « client » tant que ce profil
     * prestataire n'est pas validé : son espace client ne doit pas se fermer en attendant l'équipe KoudMain.
     */
    public function espace(): string
    {
        return match (true) {
            $this->est_admin => 'admin',
            $this->est_prestataire && ($this->est_valide || ! $this->est_client) => 'prestataire',
            default => 'client',
        };
    }

    /**
     * Les espaces que ce compte peut ouvrir dans l'application mobile : « client », « prestataire », ou les deux.
     * Aucun pour un administrateur (il travaille sur le site).
     *
     * @return list<string>
     */
    public function espaces(): array
    {
        if ($this->est_admin) {
            return [];
        }

        return array_keys(array_filter(['client' => (bool) $this->est_client, 'prestataire' => (bool) $this->est_prestataire]));
    }

    /**
     * L'espace dans lequel l'application travaille (en-tête « X-Espace ») s'il est ouvert à ce compte, sinon son espace par défaut.
     * Ne donne AUCUN droit : il choisit seulement le point de vue d'une lecture (commandes, séquestre du wallet).
     * Les actions restent protégées par aLeRole() et par l'appartenance de la commande.
     */
    public function espaceDemande(?string $demande): string
    {
        return in_array($demande, $this->espaces(), true) ? $demande : $this->espace();
    }

    /** Nom de la route de l'espace personnel de cet utilisateur. */
    public function routeTableauDeBord(): string
    {
        return $this->espace().'.tableau-de-bord';
    }

    /** Rôle affiché sous le nom, dans l'en-tête des espaces. */
    public function libelleRole(): string
    {
        return match ($this->espace()) {
            'admin' => 'Administrateur',
            'prestataire' => 'Prestataire',
            default => 'Client',
        };
    }

    // ---------------------------------------------------------- Relations

    public function quartier(): BelongsTo
    {
        return $this->belongsTo(Quartier::class);
    }

    /** Les demandes de vérification d'identité (KYC) de ce prestataire. */
    public function demandesKyc(): HasMany
    {
        return $this->hasMany(DemandeKyc::class);
    }

    /** La dernière demande de vérification d'identité (celle qui compte). */
    public function demandeKyc(): HasOne
    {
        return $this->hasOne(DemandeKyc::class)->latestOfMany();
    }

    /**
     * Où en est la vérification d'identité : null (pas prestataire), « a_fournir », « en_attente », « refusee » ou « validee ».
     * Un prestataire validé par l'équipe (même sans demande, ex. inscrit sur le site) est « validee ».
     */
    public function statutKyc(): ?string
    {
        if (! $this->est_prestataire || $this->est_admin) {
            return null;
        }

        if ($this->est_valide) {
            return DemandeKyc::VALIDEE;
        }

        // Une demande « validee » sans compte validé (validation retirée) redevient « en attente » de l'équipe.
        return match ($this->demandeKyc?->statut) {
            null => 'a_fournir',
            DemandeKyc::VALIDEE => DemandeKyc::EN_ATTENTE,
            default => $this->demandeKyc->statut,
        };
    }

    /** Les prestations proposées par ce prestataire. */
    public function prestations(): HasMany
    {
        return $this->hasMany(Prestation::class, 'prestataire_id');
    }

    /** Les commandes passées par ce client. */
    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'client_id');
    }

    /** Les commandes reçues par ce prestataire. */
    public function commandesRecues(): HasMany
    {
        return $this->hasMany(Commande::class, 'prestataire_id');
    }

    /** Les plages d'ouverture hebdomadaires (prestataire). */
    public function disponibilites(): HasMany
    {
        return $this->hasMany(Disponibilite::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** Photo de profil (une seule : garantie par un index unique en base). */
    public function avatar(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('type', Media::TYPE_AVATAR);
    }
}
