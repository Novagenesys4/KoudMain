<?php

namespace App\Services\Notifications;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prévenir quelqu'un : une notification dans le site (la cloche), poussée en direct vers son navigateur,
 * et, si l'événement le mérite, un e-mail.
 *
 * On utilise la table native `notifications` de Laravel : $utilisateur->notifications, ->unreadNotifications... marchent tels quels.
 * Aucune erreur d'envoi (e-mail en panne, etc.) ne remonte à l'action qui a déclenché la notification :
 * commander ou payer ne doit jamais échouer parce qu'un e-mail n'est pas parti.
 */
class NotificationService
{
    public function __construct(private readonly Diffuseur $diffuseur)
    {
    }

    /**
     * @param  string  $categorie  ex. « commande_acceptee » : sert à choisir l'icône côté client et à filtrer plus tard
     * @param  string  $url  chemin interne (ex. « /client/commandes/12 »)
     * @param  bool  $email  envoyer aussi un e-mail (si l'utilisateur ne l'a pas refusé)
     * @param  string|null  $bouton  libellé du bouton de l'e-mail (par défaut « Ouvrir »)
     */
    public function envoyer(User $utilisateur, string $categorie, string $titre, string $texte, string $url, string $icone = 'cloche', bool $email = false, ?string $bouton = null): ?DatabaseNotification
    {
        try {
            /** @var DatabaseNotification $notification */
            $notification = $utilisateur->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $categorie,
                'data' => ['titre' => $titre, 'texte' => $texte, 'url' => $url, 'icone' => $icone, 'categorie' => $categorie],
            ]);
        } catch (Throwable $e) {
            Log::warning('notification.creation_echouee', ['utilisateur' => $utilisateur->id, 'categorie' => $categorie, 'erreur' => $e->getMessage()]);

            return null;
        }

        $this->diffuseur->vers($utilisateur, 'notification', $this->representer($notification) + ['non_lues' => $this->nonLues($utilisateur)]);

        if ($email) {
            $this->envoyerEmail($utilisateur, $titre, $texte, $url, $bouton);
        }

        return $notification;
    }

    /** @param  iterable<User>  $utilisateurs */
    public function envoyerA(iterable $utilisateurs, string $categorie, string $titre, string $texte, string $url, string $icone = 'cloche', bool $email = false, ?string $bouton = null): void
    {
        foreach ($utilisateurs as $utilisateur) {
            $this->envoyer($utilisateur, $categorie, $titre, $texte, $url, $icone, $email, $bouton);
        }
    }

    /** Un e-mail sans notification dans le site (ex. « nouveau message » : la messagerie a déjà sa propre pastille). */
    public function envoyerEmailSeul(User $utilisateur, string $titre, string $texte, string $url, ?string $bouton = null): void
    {
        $this->envoyerEmail($utilisateur, $titre, $texte, $url, $bouton);
    }

    /**
     * Un e-mail INDISPENSABLE au fonctionnement du compte (confirmation d'adresse, avertissement d'une inscription en double) :
     * il part même si la personne a coupé les e-mails de notification, et même si NOTIFICATIONS_EMAIL=false (sinon, personne ne
     * pourrait confirmer son adresse). Pas de notification dans le site : ces personnes n'ont pas (ou pas encore) de session.
     * Comme les autres e-mails, il part après l'envoi de la réponse dans une page web ; une panne d'envoi est journalisée, jamais remontée.
     */
    public function envoyerTransactionnel(string $adresse, string $prenom, string $titre, string $texte, string $lien, string $bouton): void
    {
        $envoi = function () use ($adresse, $prenom, $titre, $texte, $lien, $bouton): void {
            try {
                Mail::to($adresse)->send(new NotificationMail($prenom, $titre, $texte, $lien, $bouton));
            } catch (Throwable $e) {
                Log::warning('email_transactionnel.echoue', ['erreur' => mb_substr($e->getMessage(), 0, 200)]);
            }
        };

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $envoi();
        } else {
            app()->terminating($envoi);
        }
    }

    public function nonLues(User $utilisateur): int
    {
        return $utilisateur->unreadNotifications()->count();
    }

    /** @return Collection<int, DatabaseNotification> */
    public function recentes(User $utilisateur, int $nombre = 10): Collection
    {
        return $utilisateur->notifications()->latest()->limit($nombre)->get();
    }

    /** Marque comme lue(s) : une notification (son identifiant) ou toutes. @return int nombre de notifications marquées */
    public function marquerLues(User $utilisateur, ?string $id = null): int
    {
        $requete = $utilisateur->unreadNotifications();

        if ($id !== null) {
            $requete->whereKey($id);
        }

        $nombre = $requete->update(['read_at' => now()]);

        // Les autres onglets de la même personne mettent à jour leur pastille.
        $this->diffuseur->vers($utilisateur, 'notifications_lues', ['non_lues' => $this->nonLues($utilisateur), 'id' => $id]);

        return $nombre;
    }

    /** La forme d'une notification pour l'écran (cloche, page, toast). @return array<string, mixed> */
    public function representer(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'titre' => (string) ($notification->data['titre'] ?? ''),
            'texte' => (string) ($notification->data['texte'] ?? ''),
            'url' => (string) ($notification->data['url'] ?? '/'),
            'icone' => (string) ($notification->data['icone'] ?? 'cloche'),
            'categorie' => (string) ($notification->data['categorie'] ?? $notification->type),
            'lue' => $notification->read_at !== null,
            'date' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function envoyerEmail(User $utilisateur, string $titre, string $texte, string $url, ?string $bouton): void
    {
        if (! config('koudmain.notifications.email') || ! $utilisateur->notifications_email || $utilisateur->email === '') {
            return;
        }

        $adresse = $utilisateur->email;
        $prenom = $utilisateur->prenom;
        $lien = str_starts_with($url, 'http') ? $url : url($url);

        $envoi = function () use ($adresse, $prenom, $titre, $texte, $lien, $bouton): void {
            try {
                Mail::to($adresse)->send(new NotificationMail($prenom, $titre, $texte, $lien, $bouton ?? 'Ouvrir KoudMain'));
            } catch (Throwable $e) {
                Log::warning('notification.email_echoue', ['erreur' => $e->getMessage()]);
            }
        };

        // Dans une page web, l'e-mail part APRÈS l'envoi de la réponse : la personne n'attend pas le serveur d'e-mail.
        // En console (tâches planifiées, tests), il part tout de suite.
        if (app()->runningInConsole() || app()->runningUnitTests()) {
            $envoi();
        } else {
            app()->terminating($envoi);
        }
    }
}
