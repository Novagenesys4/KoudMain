<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

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

    /** L'espace de cet utilisateur : « admin », « prestataire » ou « client » (l'administrateur passe en premier). */
    public function espace(): string
    {
        return match (true) {
            $this->est_admin => 'admin',
            $this->est_prestataire => 'prestataire',
            default => 'client',
        };
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
