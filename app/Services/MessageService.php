<?php

namespace App\Services;

use App\Enums\StatutCommande;
use App\Exceptions\OperationRefusee;
use App\Models\Commande;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\TempsReel\Diffuseur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La messagerie : une conversation par commande, entre son client et son prestataire.
 *
 * Toutes les règles sont ici (qui a le droit d'écrire, longueur, « lu ») ; les contrôleurs et l'interface ne font que les appeler.
 * Chaque envoi est poussé en direct vers les deux personnes (Diffuseur) : le destinataire voit le message arriver sans actualiser,
 * et l'expéditeur le voit apparaître dans ses autres onglets.
 */
class MessageService
{
    public function __construct(
        private readonly Diffuseur $diffuseur,
        private readonly NotificationService $notifications,
    ) {
    }

    // ------------------------------------------------------------ Droits

    public function participe(Commande $commande, User $utilisateur): bool
    {
        return $commande->client_id === $utilisateur->id || $commande->prestataire_id === $utilisateur->id;
    }

    /** L'identifiant de l'AUTRE personne de la commande. */
    public function interlocuteurId(Commande $commande, User $utilisateur): int
    {
        return $commande->client_id === $utilisateur->id ? $commande->prestataire_id : $commande->client_id;
    }

    // ---------------------------------------------------------- Lecture

    /**
     * Les messages d'une commande, du plus ancien au plus récent, par tranche : les `$nombre` derniers,
     * ou les `$nombre` précédant le message `$avant` (bouton « Messages plus anciens »).
     *
     * @return array{messages: Collection<int, Message>, plus_anciens: bool}
     */
    public function messages(Commande $commande, ?int $avant = null, ?int $nombre = null): array
    {
        $nombre ??= (int) config('koudmain.messagerie.par_page');
        $conversation = Conversation::query()->where('commande_id', $commande->id)->first();

        if ($conversation === null) {
            return ['messages' => collect(), 'plus_anciens' => false];
        }

        $requete = Message::query()->where('conversation_id', $conversation->id)->orderByDesc('id');

        if ($avant !== null) {
            $requete->where('id', '<', $avant);
        }

        $lot = $requete->limit($nombre + 1)->get();

        return ['messages' => $lot->take($nombre)->reverse()->values(), 'plus_anciens' => $lot->count() > $nombre];
    }

    /** Combien de messages n'ont pas encore été lus par cet utilisateur (tous ses échanges confondus). */
    public function nonLus(User $utilisateur): int
    {
        return (int) DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('commandes as o', 'o.id', '=', 'c.commande_id')
            ->where('m.lu', false)
            ->where('m.expediteur_id', '<>', $utilisateur->id)
            ->where(fn ($q) => $q->where('o.client_id', $utilisateur->id)->orWhere('o.prestataire_id', $utilisateur->id))
            ->count();
    }

    /**
     * La liste des échanges de l'utilisateur : ses commandes qui ont une discussion, plus celles qui sont en cours de route
     * (pour pouvoir écrire en premier), la plus récente activité en haut.
     *
     * @return list<array<string, mixed>>
     */
    public function echanges(User $utilisateur, int $limite = 40): array
    {
        $apercu = Message::query()->select('messages.contenu')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereColumn('conversations.commande_id', 'commandes.id')
            ->orderByDesc('messages.id')->limit(1);

        $dernierePar = Message::query()->select('messages.expediteur_id')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereColumn('conversations.commande_id', 'commandes.id')
            ->orderByDesc('messages.id')->limit(1);

        $dernierAt = Conversation::query()->select('dernier_message_at')->whereColumn('commande_id', 'commandes.id')->limit(1);

        $nonLus = Message::query()->selectRaw('count(*)')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereColumn('conversations.commande_id', 'commandes.id')
            ->where('messages.lu', false)->where('messages.expediteur_id', '<>', $utilisateur->id);

        // Une commande apparaît si elle a déjà une discussion, ou si elle est encore « vivante » (pour pouvoir écrire en premier).
        $vivantes = [StatutCommande::EnAttente->value, StatutCommande::Acceptee->value, StatutCommande::EnCours->value, StatutCommande::Litige->value];

        return Commande::query()
            ->where(fn ($q) => $q->where('client_id', $utilisateur->id)->orWhere('prestataire_id', $utilisateur->id))
            ->where(fn ($q) => $q->whereIn('commandes.statut', $vivantes)->orWhereExists(
                fn ($e) => $e->selectRaw('1')->from('conversations')->whereColumn('conversations.commande_id', 'commandes.id'),
            ))
            ->with(['client.avatar', 'prestataire.avatar', 'prestations'])
            ->addSelect(['commandes.*', 'apercu' => $apercu, 'derniere_par' => $dernierePar, 'dernier_message_at' => $dernierAt, 'non_lus' => $nonLus])
            ->orderByRaw('coalesce((select dernier_message_at from conversations where conversations.commande_id = commandes.id), commandes.created_at) desc')
            ->limit($limite)
            ->get()
            ->map(fn (Commande $c) => $this->echange($c, $utilisateur))
            ->all();
    }

    /** Un échange (une ligne de la liste, l'en-tête d'une discussion). @return array<string, mixed> */
    public function echange(Commande $commande, User $pour): array
    {
        $autre = $commande->client_id === $pour->id ? $commande->prestataire : $commande->client;

        return [
            'commande_id' => $commande->id,
            'titre' => $commande->prestations->first()?->titre ?? 'Commande n° '.$commande->id,
            'statut' => $commande->statut->libelle(),
            'nuance' => $commande->statut->nuance(),
            'autre' => [
                'id' => $autre->id,
                'nom' => $autre->nom_complet,
                'prenom' => $autre->prenom,
                'avatar' => $autre->avatar?->url(),
                'teinte' => \App\Support\Presentateur::teinte($autre),
                'role' => $commande->client_id === $pour->id ? 'Prestataire' : 'Client',
            ],
            'apercu' => isset($commande->apercu) ? Str::limit((string) $commande->apercu, 70) : null,
            'apercu_moi' => isset($commande->derniere_par) ? (int) $commande->derniere_par === $pour->id : false,
            'date' => isset($commande->dernier_message_at) ? \Illuminate\Support\Carbon::parse($commande->dernier_message_at)->toIso8601String() : $commande->created_at->toIso8601String(),
            'a_des_messages' => isset($commande->dernier_message_at),
            'non_lus' => (int) ($commande->non_lus ?? 0),
            'url' => route('messages.voir', $commande),
        ];
    }

    /**
     * Le résumé d'une discussion pour la page de la commande : combien de messages, combien de non lus pour cette personne, le dernier.
     *
     * @return array{nombre: int, non_lus: int, apercu: string|null, apercu_moi: bool}
     */
    public function resume(Commande $commande, User $utilisateur): array
    {
        $conversationId = Conversation::query()->where('commande_id', $commande->id)->value('id');

        if ($conversationId === null) {
            return ['nombre' => 0, 'non_lus' => 0, 'apercu' => null, 'apercu_moi' => false];
        }

        $dernier = Message::query()->where('conversation_id', $conversationId)->orderByDesc('id')->first();

        return [
            'nombre' => Message::query()->where('conversation_id', $conversationId)->count(),
            'non_lus' => Message::query()->where('conversation_id', $conversationId)->where('lu', false)->where('expediteur_id', '<>', $utilisateur->id)->count(),
            'apercu' => $dernier ? Str::limit($dernier->contenu, 90) : null,
            'apercu_moi' => $dernier !== null && $dernier->expediteur_id === $utilisateur->id,
        ];
    }

    /**
     * Les derniers messages d'une commande, en LECTURE SEULE, pour l'administrateur qui tranche un litige.
     *
     * @return Collection<int, Message>
     */
    public function transcription(Commande $commande, int $nombre = 40): Collection
    {
        $conversationId = Conversation::query()->where('commande_id', $commande->id)->value('id');

        if ($conversationId === null) {
            return collect();
        }

        return Message::query()->where('conversation_id', $conversationId)->with('expediteur:id,prenom,nom')->orderByDesc('id')->limit($nombre)->get()->reverse()->values();
    }

    /** @return array<string, mixed> */
    public function representer(Message $message): array
    {
        return [
            'id' => $message->id,
            'expediteur_id' => $message->expediteur_id,
            'contenu' => $message->contenu,
            'lu' => $message->lu,
            'date' => $message->created_at->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------- Écriture

    /**
     * @throws OperationRefusee
     */
    public function envoyer(Commande $commande, User $expediteur, string $contenu): Message
    {
        if (! $this->participe($commande, $expediteur)) {
            throw new OperationRefusee('Vous ne faites pas partie de cette conversation.');
        }

        $contenu = $this->nettoyer($contenu);
        $max = (int) config('koudmain.messagerie.longueur_max');

        if ($contenu === '') {
            throw new OperationRefusee('Écrivez un message avant de l\'envoyer.');
        }

        if (mb_strlen($contenu) > $max) {
            throw new OperationRefusee("Un message ne peut pas dépasser $max caractères.");
        }

        $destinataireId = $this->interlocuteurId($commande, $expediteur);

        /** @var array{0: Message, 1: bool} $resultat */
        $resultat = DB::transaction(function () use ($commande, $expediteur, $contenu): array {
            DB::table('conversations')->insertOrIgnore(['commande_id' => $commande->id, 'created_at' => now(), 'updated_at' => now()]);
            $conversation = Conversation::query()->where('commande_id', $commande->id)->lockForUpdate()->firstOrFail();

            // Un e-mail « nouveau message » ne part que pour le premier message d'une série (pas un e-mail par message).
            $premierDeLaSerie = ! Message::query()->where('conversation_id', $conversation->id)->where('expediteur_id', $expediteur->id)->where('lu', false)->exists();

            $message = new Message();
            $message->forceFill(['conversation_id' => $conversation->id, 'expediteur_id' => $expediteur->id, 'contenu' => $contenu, 'lu' => false])->save();
            $conversation->forceFill(['dernier_message_at' => $message->created_at])->save();

            return [$message, $premierDeLaSerie];
        });

        [$message, $premierDeLaSerie] = $resultat;

        $destinataire = User::query()->find($destinataireId);
        $representation = $this->representer($message);

        // En direct chez le destinataire, et dans les autres onglets de l'expéditeur.
        $this->diffuseur->vers($destinataireId, 'message', ['commande_id' => $commande->id, 'message' => $representation, 'non_lus_total' => $this->nonLus($destinataire), 'de' => $expediteur->prenom]);
        $this->diffuseur->vers($expediteur, 'message', ['commande_id' => $commande->id, 'message' => $representation, 'non_lus_total' => $this->nonLus($expediteur), 'de' => $expediteur->prenom]);

        if ($premierDeLaSerie && $destinataire !== null && ! $this->diffuseur->estPresent($destinataireId)) {
            $this->notifications->envoyerEmailSeul(
                $destinataire, 'Nouveau message de '.$expediteur->prenom,
                Str::limit($contenu, 160), '/messages/'.$commande->id, 'Répondre',
            );
        }

        return $message;
    }

    /** Le lecteur a vu la discussion : ses messages reçus passent à « lu », et l'autre personne le voit (✓✓). @return int nombre de messages marqués */
    public function marquerLus(Commande $commande, User $lecteur): int
    {
        if (! $this->participe($commande, $lecteur)) {
            return 0;
        }

        $conversationId = Conversation::query()->where('commande_id', $commande->id)->value('id');

        if ($conversationId === null) {
            return 0;
        }

        $nombre = Message::query()->where('conversation_id', $conversationId)->where('expediteur_id', '<>', $lecteur->id)->where('lu', false)->update(['lu' => true]);

        if ($nombre > 0) {
            $this->diffuseur->vers($this->interlocuteurId($commande, $lecteur), 'messages_lus', ['commande_id' => $commande->id]);
            $this->diffuseur->vers($lecteur, 'non_lus', ['commande_id' => $commande->id, 'non_lus_total' => $this->nonLus($lecteur)]);
        }

        return $nombre;
    }

    /** « X est en train d'écrire… » : un signal éphémère vers l'autre personne. */
    public function ecrit(Commande $commande, User $expediteur): void
    {
        if ($this->participe($commande, $expediteur)) {
            $this->diffuseur->vers($this->interlocuteurId($commande, $expediteur), 'ecrit', ['commande_id' => $commande->id, 'utilisateur_id' => $expediteur->id]);
        }
    }

    /** Trim, fins de ligne uniformes, pas plus d'une ligne vide de suite, pas de caractères de contrôle. */
    private function nettoyer(string $contenu): string
    {
        $contenu = preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", $contenu)) ?? '';
        $contenu = preg_replace("/\n{3,}/", "\n\n", $contenu) ?? '';

        return trim($contenu);
    }
}
