<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Prestation;
use App\Services\FavoriService;
use App\Support\Presentateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /client/favoris : les prestations mises de côté, et le cœur qui les ajoute ou les retire. */
class FavoriController extends Controller
{
    public function __construct(private readonly FavoriService $favoris)
    {
    }

    public function index(Request $request): View
    {
        $page = $this->favoris->liste($request->user());
        $ids = $page->getCollection()->pluck('id')->all(); // toute la page est en favori : le cœur est plein

        return view('client.favoris', [
            'page' => $page,
            'cartes' => $page->getCollection()->map(fn (Prestation $p) => Presentateur::carte($p, $ids))->values()->all(),
        ]);
    }

    /** Le cœur : répond en JSON pour la carte animée, ou revient à la page (sans JavaScript). */
    public function basculer(Request $request, Prestation $prestation): JsonResponse|RedirectResponse
    {
        $favori = $this->favoris->basculer($request->user(), $prestation);

        if ($request->expectsJson()) {
            return response()->json(['favori' => $favori]);
        }

        return back()->with('succes', $favori ? 'Ajouté à vos favoris.' : 'Retiré de vos favoris.');
    }
}
