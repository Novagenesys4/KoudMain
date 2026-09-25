<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Commande;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La messagerie : une discussion par commande, entre son client et son prestataire. Règles dans MessageService.
 * Pas encore de temps réel dans l'application : elle rafraîchit la discussion ouverte (toutes les 5 s) et la liste au retour.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function conversations(Request $request): JsonResponse
    {
        $liste = array_map(function (array $e): array {
            unset($e['url']);

            return $e;
        }, $this->messages->echanges($request->user()));

        return ApiResponse::succes($liste, meta: ['non_lus_total' => $this->messages->nonLus($request->user())]);
    }

    /** GET /commandes/{id}/messages?avant={message_id} : les 40 derniers (ou les 40 précédant « avant »). Ouvrir = lire. */
    public function index(Request $request, Commande $commande): JsonResponse
    {
        $this->verifier($request, $commande);
        $avant = $request->query('avant');
        $avant = is_string($avant) && preg_match('/^\d{1,12}$/', $avant) === 1 ? (int) $avant : null;

        if ($avant === null) {
            $this->messages->marquerLus($commande, $request->user());
        }

        $lot = $this->messages->messages($commande, $avant);
        $moi = $request->user()->id;

        return ApiResponse::succes(
            $lot['messages']->map(fn (Message $m) => $this->representer($m, $moi))->values()->all(),
            meta: ['plus_anciens' => $lot['plus_anciens']],
        );
    }

    public function store(Request $request, Commande $commande): JsonResponse
    {
        $this->verifier($request, $commande);
        $donnees = $request->validate(
            ['contenu' => ['required', 'string', 'max:10000']],
            ['contenu.required' => 'Écrivez un message avant de l\'envoyer.', 'contenu.*' => 'Ce message est trop long.'],
        );

        $message = $this->messages->envoyer($commande, $request->user(), $donnees['contenu']);

        return ApiResponse::succes($this->representer($message, $request->user()->id), 'Message envoyé.', 201);
    }

    public function lu(Request $request, Commande $commande): JsonResponse
    {
        $this->verifier($request, $commande);

        return ApiResponse::succes(['marques' => $this->messages->marquerLus($commande, $request->user())]);
    }

    /** @return array<string, mixed> */
    private function representer(Message $message, int $moi): array
    {
        return $this->messages->representer($message) + ['de_moi' => $message->expediteur_id === $moi];
    }

    private function verifier(Request $request, Commande $commande): void
    {
        abort_unless($this->messages->participe($commande, $request->user()), 404);
    }
}
