<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\Commande;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * /messages : la messagerie d'un client ou d'un prestataire (une discussion par commande).
 * La page est écrite par le serveur (elle marche sans JavaScript) ; l'îlot React la rend vivante : envoi sans rechargement,
 * messages qui arrivent en direct, « en train d'écrire », accusés de lecture.
 *
 * Une commande qui n'est pas la vôtre répond « introuvable » : on ne révèle pas qu'elle existe.
 */
class MessagerieController extends Controller
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    public function index(Request $request, ?Commande $commande = null): View
    {
        $utilisateur = $request->user();
        abort_if($utilisateur->est_admin && ! $utilisateur->est_client && ! $utilisateur->est_prestataire, 404);

        if ($commande !== null) {
            $this->verifier($request, $commande);
            // Ouvrir la discussion = la lire (sauf pour un rafraîchissement en arrière-plan de la page, qui n'est pas une lecture).
            if (! $request->ajax()) {
                $this->messages->marquerLus($commande, $utilisateur);
            }
        }

        $echanges = $this->messages->echanges($utilisateur);

        return view('espace.messages', [
            'echanges' => $echanges,
            'courante' => $commande !== null ? $this->discussion($request, $commande) : null,
            'moi' => $utilisateur->id,
            'nonLus' => $this->messages->nonLus($utilisateur),
            'urls' => [
                'fil' => route('messages.fil', ['commande' => '__ID__']),
                'envoyer' => route('messages.envoyer', ['commande' => '__ID__']),
                'lu' => route('messages.lu', ['commande' => '__ID__']),
                'ecrit' => route('messages.ecrit', ['commande' => '__ID__']),
                'page' => route('messages.voir', ['commande' => '__ID__']),
                'commande' => route($utilisateur->espace() === 'prestataire' ? 'prestataire.commandes.voir' : 'client.commandes.voir', ['commande' => '__ID__']),
                'liste' => route('messages'),
            ],
        ]);
    }

    /** La discussion d'une commande en JSON : ouverture sans recharger la page, et « messages plus anciens ». */
    public function fil(Request $request, Commande $commande): JsonResponse
    {
        $this->verifier($request, $commande);
        $avant = $request->query('avant');

        if (! is_numeric($avant)) {
            $this->messages->marquerLus($commande, $request->user());
        }

        return response()->json($this->discussion($request, $commande, is_numeric($avant) ? (int) $avant : null), headers: ['Cache-Control' => 'no-store']);
    }

    public function envoyer(Request $request, Commande $commande): JsonResponse|RedirectResponse
    {
        $this->verifier($request, $commande);
        $donnees = $request->validate(['contenu' => ['required', 'string', 'max:10000']], ['contenu.required' => 'Écrivez un message avant de l\'envoyer.', 'contenu.max' => 'Ce message est beaucoup trop long.']);

        try {
            $message = $this->messages->envoyer($commande, $request->user(), $donnees['contenu']);
        } catch (OperationRefusee $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()->route('messages.voir', $commande)->with('erreur', $e->getMessage())->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $this->messages->representer($message)], 201);
        }

        return redirect()->route('messages.voir', $commande);
    }

    public function lu(Request $request, Commande $commande): Response
    {
        $this->verifier($request, $commande);
        $this->messages->marquerLus($commande, $request->user());

        return response()->noContent();
    }

    public function ecrit(Request $request, Commande $commande): Response
    {
        $this->verifier($request, $commande);
        $this->messages->ecrit($commande, $request->user());

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function discussion(Request $request, Commande $commande, ?int $avant = null): array
    {
        $commande->loadMissing(['client.avatar', 'prestataire.avatar', 'prestations']);
        $lot = $this->messages->messages($commande, $avant);

        return [
            'echange' => $this->messages->echange($commande, $request->user()),
            'messages' => $lot['messages']->map(fn ($m) => $this->messages->representer($m))->values()->all(),
            'plus_anciens' => $lot['plus_anciens'],
        ];
    }

    private function verifier(Request $request, Commande $commande): void
    {
        abort_unless($this->messages->participe($commande, $request->user()), 404);
    }
}
