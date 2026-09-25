<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionCommande;
use App\Enums\ModePaiement;
use App\Enums\StatutCommande;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CommanderApiRequest;
use App\Http\Resources\Api\CommandeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Commande;
use App\Models\Prestation;
use App\Services\AvisService;
use App\Services\CommandeService;
use App\Services\MessageService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les commandes d'un client (qu'il a passées) ou d'un prestataire (qu'il a reçues). Toute la logique (statuts, séquestre,
 * remboursement, libération) est dans CommandeService : ici, seulement le HTTP. Une commande dont on n'est pas partie
 * répond 404 (on ne révèle pas qu'elle existe).
 */
class CommandeController extends Controller
{
    private const RELATIONS = ['prestations', 'client.avatar', 'prestataire.avatar', 'prestataire.quartier.ville', 'quartier.ville', 'escrow', 'avis'];

    private const MESSAGES = [
        'accepter' => 'Commande acceptée. Le créneau est réservé.',
        'demarrer' => 'Prestation démarrée.',
        'terminer' => 'Prestation marquée comme terminée. Le client doit valider pour libérer le paiement.',
        'annuler' => 'Commande annulée.',
        'ouvrir_litige' => 'Signalement envoyé. L\'argent reste bloqué : un médiateur KoudMain examine la situation.',
        'confirmer_reception' => 'Réception confirmée. Le prestataire est payé.',
    ];

    public function __construct(private readonly CommandeService $commandes) {}

    /**
     * GET /commandes?role=client|prestataire&onglet=en_cours|terminees|nouvelles&statut=…&page=
     *  - en_cours  : en attente, acceptée, en cours, litige, terminée mais pas encore validée ;
     *  - terminees : terminée et validée, annulée ;
     *  - nouvelles : en attente (côté prestataire : les demandes à accepter).
     */
    public function index(Request $request): JsonResponse
    {
        $moi = $request->user();
        // Point de vue : « role » s'il est précisé, sinon l'espace ouvert dans l'application (en-tête X-Espace), sinon l'espace par défaut.
        $role = in_array($request->query('role'), ['client', 'prestataire'], true)
            ? $request->query('role')
            : ($moi->espaceDemande($request->header('X-Espace')) === 'prestataire' ? 'prestataire' : 'client');
        $colonne = $role === 'client' ? 'client_id' : 'prestataire_id';

        $base = Commande::query()->where($colonne, $moi->id);
        $requete = (clone $base)->with(self::RELATIONS)->latest('id');

        match ($request->query('onglet')) {
            'en_cours' => $requete->where(fn (Builder $q) => $q
                ->whereIn('statut', [StatutCommande::EnAttente->value, StatutCommande::Acceptee->value, StatutCommande::EnCours->value, StatutCommande::Litige->value])
                ->orWhere(fn (Builder $t) => $t->where('statut', StatutCommande::Terminee->value)->whereNull('validee_client_at'))),
            'terminees' => $requete->where(fn (Builder $q) => $q
                ->where('statut', StatutCommande::Annulee->value)
                ->orWhere(fn (Builder $t) => $t->where('statut', StatutCommande::Terminee->value)->whereNotNull('validee_client_at'))),
            'nouvelles' => $requete->where('statut', StatutCommande::EnAttente->value),
            default => null,
        };

        if (is_string($statut = $request->query('statut')) && ($enum = StatutCommande::tryFrom($statut)) !== null) {
            $requete->where('statut', $enum->value);
        }

        $compteurs = (clone $base)->toBase()->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut')->map(fn ($n) => (int) $n)->all();

        return ApiResponse::pagine($requete->paginate(15), CommandeResource::class, meta: ['role' => $role, 'compteurs' => (object) $compteurs]);
    }

    /** POST /commandes : réserver. Selon le mode, le montant quitte le wallet et reste en séquestre (mobile_money) ou se règle en main propre (physique). */
    public function store(CommanderApiRequest $request): JsonResponse
    {
        $prestation = Prestation::query()->whereKey((int) $request->validated('prestation_id'))->firstOrFail();

        $commande = $this->commandes->commander(
            $request->user(),
            $prestation,
            (int) $request->validated('quantite'),
            $request->debut(),
            $request->validated('precisions'),
            $request->validated('quartier_id') !== null ? (int) $request->validated('quartier_id') : null,
            $request->mode() === ModePaiement::Carte && $request->validated('carte_id') !== null ? (int) $request->validated('carte_id') : null,
            $request->mode(),
        );

        $message = $commande->mode_paiement === ModePaiement::Physique
            ? 'Demande envoyée. Vous paierez '.Format::fcfa($commande->montant_total).' au prestataire en main propre, à la fin de la prestation.'
            : 'Réservation envoyée, paiement protégé : '.Format::fcfa($commande->montant_total).' sont bloqués en séquestre jusqu\'à votre validation.';

        return ApiResponse::succes(new CommandeResource($commande->load(self::RELATIONS)), $message, 201);
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        $this->verifier($request, $commande);
        $commande->load(self::RELATIONS);

        return ApiResponse::succes((new CommandeResource($commande))->resolve() + [
            'discussion' => app(MessageService::class)->resume($commande, $request->user()),
        ]);
    }

    /**
     * POST /commandes/{id}/actions/{action} — accepter | demarrer | terminer (prestataire), annuler (les deux),
     * ouvrir_litige (client, motif ≥ 10 caractères), confirmer_reception (client : libère le séquestre).
     */
    public function agir(Request $request, Commande $commande, string $action): JsonResponse
    {
        $this->verifier($request, $commande);
        $action = ActionCommande::tryFrom($action) ?? abort(404);
        $request->validate(['motif' => ['nullable', 'string', 'max:1000']], ['motif.*' => 'Le message ne peut pas dépasser 1000 caractères.']);

        $motif = $request->input('motif');
        $maj = $this->commandes->agir($commande, $request->user(), $action, is_string($motif) ? $motif : null);

        $message = $maj->payeeEnPhysique() && $action === ActionCommande::ConfirmerReception ? 'Réception confirmée. Merci !' : self::MESSAGES[$action->value];

        return ApiResponse::succes(new CommandeResource($maj->load(self::RELATIONS)), $message);
    }

    /** POST /commandes/{id}/avis : note (1-5) et commentaire d'une commande terminée. Sans prestation_id : la première de la commande. */
    public function avis(Request $request, Commande $commande, AvisService $avis): JsonResponse
    {
        $this->verifier($request, $commande);

        $donnees = $request->validate([
            'note' => ['required', 'integer', 'min:1', 'max:5'],
            'commentaire' => ['nullable', 'string', 'max:'.AvisService::COMMENTAIRE_MAX],
            'prestation_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'note.*' => 'Choisissez une note de 1 à 5.',
            'commentaire.max' => 'Votre avis ne peut pas dépasser '.AvisService::COMMENTAIRE_MAX.' caractères.',
            'commentaire.*' => 'Votre avis n\'est pas valide.',
            'prestation_id.*' => 'Cette prestation ne fait pas partie de la commande.',
        ]);

        $prestationId = isset($donnees['prestation_id']) ? (int) $donnees['prestation_id'] : (int) $commande->prestations()->value('prestations.id');
        $donne = $avis->donner($commande, $request->user(), $prestationId, (int) $donnees['note'], $donnees['commentaire'] ?? null);

        return ApiResponse::succes([
            'prestation_id' => $donne->prestation_id,
            'note' => $donne->note,
            'commentaire' => $donne->commentaire,
        ], 'Merci ! Votre avis est publié.', $donne->wasRecentlyCreated ? 201 : 200);
    }

    private function verifier(Request $request, Commande $commande): void
    {
        $moi = $request->user()->id;

        abort_unless($commande->client_id === $moi || $commande->prestataire_id === $moi, 404);
    }
}
