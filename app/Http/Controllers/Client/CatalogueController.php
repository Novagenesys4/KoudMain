<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\CatalogueController as CataloguePublic;
use App\Services\FavoriService;
use Illuminate\Http\Request;

/** /client/catalogue : le catalogue, dans l'espace du client (mêmes résultats que la page publique, avec le cœur des favoris). */
class CatalogueController extends CataloguePublic
{
    protected string $vue = 'client.catalogue';

    protected string $nomRoute = 'client.catalogue';

    protected function favoris(Request $request, array $prestationIds): ?array
    {
        return app(FavoriService::class)->parmi($request->user(), $prestationIds);
    }
}
