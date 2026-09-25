<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\MessageService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Les notifications (la cloche). Chacun ne voit que les siennes. « cible » dit à l'application quel écran ouvrir
 * (déduit du lien interne enregistré : /client/commandes/12 → { type: commande, id: 12 }).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** GET /notifications?non_lues=1&page= */
    public function index(Request $request): JsonResponse
    {
        $moi = $request->user();
        $requete = $request->boolean('non_lues') ? $moi->unreadNotifications() : $moi->notifications();

        return ApiResponse::pagine(
            $requete->latest()->paginate(20),
            fn (DatabaseNotification $n) => $this->representer($n),
            meta: ['non_lues' => $this->notifications->nonLues($moi)],
        );
    }

    /** Les pastilles : notifications et messages non lus. */
    public function compteurs(Request $request, MessageService $messages): JsonResponse
    {
        return ApiResponse::succes([
            'notifications_non_lues' => $this->notifications->nonLues($request->user()),
            'messages_non_lus' => $messages->nonLus($request->user()),
        ]);
    }

    public function lire(Request $request, string $notification): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $notification) === 1, 404);
        abort_if($request->user()->notifications()->whereKey($notification)->doesntExist(), 404);

        $this->notifications->marquerLues($request->user(), $notification);

        return ApiResponse::succes(['non_lues' => $this->notifications->nonLues($request->user())]);
    }

    public function toutLire(Request $request): JsonResponse
    {
        $this->notifications->marquerLues($request->user());

        return ApiResponse::succes(['non_lues' => 0], 'Toutes vos notifications sont marquées comme lues.');
    }

    /** @return array<string, mixed> */
    private function representer(DatabaseNotification $notification): array
    {
        $donnees = $this->notifications->representer($notification);
        $url = $donnees['url'];
        unset($donnees['url']);

        $cible = match (true) {
            preg_match('#/commandes/(\d+)#', $url, $m) === 1 => ['type' => 'commande', 'id' => (int) $m[1]],
            preg_match('#/messages/(\d+)#', $url, $m) === 1 => ['type' => 'messages', 'id' => (int) $m[1]],
            str_contains($url, '/wallet') => ['type' => 'wallet', 'id' => null],
            default => null,
        };

        return $donnees + ['cible' => $cible];
    }
}
