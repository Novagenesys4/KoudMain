<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Les notifications d'un utilisateur : la page complète, la liste courte de la cloche (JSON), et « marquer comme lu ».
 * Chacun ne voit que les siennes : tout passe par $request->user()->notifications().
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): View
    {
        $utilisateur = $request->user();
        $seulementNonLues = $request->query('filtre') === 'non-lues';

        $requete = $seulementNonLues ? $utilisateur->unreadNotifications() : $utilisateur->notifications();
        $page = $requete->latest()->paginate(20)->withQueryString();

        return view('espace.notifications', [
            'notifications' => $page->through(fn ($n) => $this->notifications->representer($n)),
            'page' => $page,
            'nonLues' => $this->notifications->nonLues($utilisateur),
            'seulementNonLues' => $seulementNonLues,
        ]);
    }

    /** La liste courte de la cloche. */
    public function recentes(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return response()->json([
            'non_lues' => $this->notifications->nonLues($utilisateur),
            'notifications' => $this->notifications->recentes($utilisateur, 8)->map(fn ($n) => $this->notifications->representer($n))->values(),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /** Ouvre une notification : elle est marquée lue, puis on va à la page concernée (toujours un chemin de CE site). */
    public function lire(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $trouvee = $request->user()->notifications()->whereKey($notification)->first();
        abort_if($trouvee === null, 404);

        $this->notifications->marquerLues($request->user(), $trouvee->id);

        if ($request->expectsJson()) {
            return response()->json(['non_lues' => $this->notifications->nonLues($request->user())]);
        }

        $url = (string) ($trouvee->data['url'] ?? '/');

        // Une adresse enregistrée est toujours un chemin interne ; on vérifie quand même, une redirection ouverte est une faille.
        return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '/');
    }

    public function toutLire(Request $request): RedirectResponse|JsonResponse
    {
        $this->notifications->marquerLues($request->user());

        if ($request->expectsJson()) {
            return response()->json(['non_lues' => 0]);
        }

        return back()->with('succes', 'Toutes vos notifications sont marquées comme lues.');
    }
}
