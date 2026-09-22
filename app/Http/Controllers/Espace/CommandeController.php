<?php

namespace App\Http\Controllers\Espace;

use App\Support\Saisie;
use App\Enums\ActionCommande;
use App\Enums\StatutCommande;
use App\Exceptions\OperationRefusee;
use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Services\AvisService;
use App\Services\CommandeService;
use App\Services\MessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Les commandes vues par un client (/client/commandes), un prestataire (/prestataire/commandes) ou un administrateur
 * (/admin/commandes). La route dit le rôle (defaults('role', ...)) ; chacun ne voit que SES commandes,
 * l'administrateur les voit toutes (en lecture, plus l'arbitrage des litiges).
 */
class CommandeController extends Controller
{
    private const MESSAGES = [
        'accepter' => 'Commande acceptée. Le créneau est réservé.',
        'demarrer' => 'Prestation démarrée.',
        'terminer' => 'Prestation marquée comme terminée. Le paiement est libéré dès que le client confirme (ou automatiquement après quelques jours).',
        'annuler' => 'Commande annulée. Le montant bloqué a été remboursé au client.',
        'ouvrir_litige' => 'Problème signalé. L\'argent reste bloqué : un administrateur va examiner la situation.',
        'confirmer_reception' => 'Réception confirmée. Le prestataire est payé.',
    ];

    public function __construct(private readonly CommandeService $commandes)
    {
    }

    public function index(Request $request): View
    {
        $role = $this->role($request);
        $utilisateur = $request->user();
        $statut = StatutCommande::tryFrom(Saisie::texte($request->query('statut'), 32));

        $base = $this->portee(Commande::query(), $role, $request);

        $requete = (clone $base)->with(['prestations', 'client', 'prestataire', 'escrow'])->latest('id');

        if ($statut !== null) {
            $requete->where('statut', $statut->value);
        }

        // Le nombre de commandes par statut, en UNE requête, pour les onglets.
        $compteurs = (clone $base)->toBase()->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut')->map(fn ($n) => (int) $n)->all();

        return view('espace.commandes.index', [
            'role' => $role,
            'commandes' => $requete->paginate(10)->withQueryString(),
            'statut' => $statut,
            'statuts' => StatutCommande::cases(),
            'compteurs' => $compteurs,
            'utilisateur' => $utilisateur,
        ]);
    }

    public function show(Request $request, Commande $commande): View
    {
        $role = $this->role($request);
        $this->verifier($request, $role, $commande);

        $commande->load(['prestations', 'client.quartier', 'client.avatar', 'prestataire.quartier', 'prestataire.avatar', 'quartier.ville', 'escrow', 'avis']);

        $messages = app(MessageService::class);

        return view('espace.commandes.show', [
            'role' => $role,
            'commande' => $commande,
            'actions' => $role === 'admin' ? [] : ActionCommande::possibles($commande, $request->user()),
            // Discussion : un résumé pour les deux parties, la transcription (lecture seule) pour l'administrateur.
            'discussion' => $role === 'admin' ? null : $messages->resume($commande, $request->user()),
            'transcription' => $role === 'admin' ? $messages->transcription($commande) : null,
            'peutNoter' => $role === 'client' && app(AvisService::class)->peutNoter($commande, $request->user()),
        ]);
    }

    public function agir(Request $request, Commande $commande, string $action): RedirectResponse
    {
        $role = $this->role($request);
        $this->verifier($request, $role, $commande);
        abort_if($role === 'admin', 404);

        $action = ActionCommande::tryFrom($action) ?? abort(404);
        $request->validate(['motif' => ['nullable', 'string', 'max:1000']], ['motif.max' => 'Le message ne peut pas dépasser 1000 caractères.']);

        try {
            $this->commandes->agir($commande, $request->user(), $action, $request->input('motif'));
        } catch (OperationRefusee $e) {
            return redirect()->route($role.'.commandes.voir', $commande)->with('erreur', $e->getMessage())->withInput();
        }

        return redirect()->route($role.'.commandes.voir', $commande)->with('succes', $this->message($action, $commande));
    }

    /** Le message de succès ; une commande payée en main propre n'a ni argent bloqué ni remboursement à évoquer. */
    private function message(ActionCommande $action, Commande $commande): string
    {
        if ($commande->payeeEnPhysique()) {
            return match ($action) {
                ActionCommande::Terminer => 'Prestation marquée comme terminée. La commande sera confirmée dès que le client valide la réception (ou automatiquement après quelques jours).',
                ActionCommande::Annuler => 'Commande annulée.',
                ActionCommande::OuvrirLitige => 'Problème signalé : un administrateur va examiner la situation.',
                ActionCommande::ConfirmerReception => 'Réception confirmée. Merci !',
                default => self::MESSAGES[$action->value],
            };
        }

        return self::MESSAGES[$action->value];
    }

    /** « client », « prestataire » ou « admin » : c'est la ROUTE qui le dit (defaults), jamais un paramètre du navigateur. */
    private function role(Request $request): string
    {
        return (string) $request->route()->defaults['role'];
    }

    /** Ce rôle, sur cette route, a-t-il le droit de voir cette commande ? 404 sinon (on ne révèle pas son existence). */
    private function verifier(Request $request, string $role, Commande $commande): void
    {
        $ok = match ($role) {
            'client' => $commande->client_id === $request->user()->id,
            'prestataire' => $commande->prestataire_id === $request->user()->id,
            'admin' => true,
            default => false,
        };

        abort_unless($ok, 404);
    }

    private function portee($requete, string $role, Request $request)
    {
        return match ($role) {
            'client' => $requete->where('client_id', $request->user()->id),
            'prestataire' => $requete->where('prestataire_id', $request->user()->id),
            default => $requete,
        };
    }
}
