<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La forme UNIQUE de toutes les réponses de l'API mobile :
 *
 *   { "success": true,  "message": "…", "data": … , "meta": {…} }            (meta : seulement pour une liste paginée)
 *   { "success": false, "message": "…", "data": null, "code": "…", "errors": {…} }  (errors : seulement pour une validation)
 *
 * « message » est toujours une phrase en français prête à afficher. « code » est un identifiant stable que l'application
 * teste (jamais le texte du message).
 */
final class ApiResponse
{
    /** @param  array<string, mixed>  $meta */
    public static function succes(mixed $data = null, string $message = 'OK', int $statut = 200, array $meta = []): JsonResponse
    {
        $corps = ['success' => true, 'message' => $message, 'data' => self::resoudre($data)];

        if ($meta !== []) {
            $corps['meta'] = $meta;
        }

        return self::json($corps, $statut);
    }

    /**
     * Une liste paginée : data = les éléments, meta = la pagination.
     *
     * @param  class-string<JsonResource>|callable  $transformer  classe de ressource, ou fonction (élément) => tableau
     * @param  array<string, mixed>  $meta
     */
    public static function pagine(LengthAwarePaginator $page, string|callable $transformer, string $message = 'OK', array $meta = []): JsonResponse
    {
        $elements = is_string($transformer)
            ? $transformer::collection($page->getCollection())->resolve()
            : $page->getCollection()->map($transformer)->values()->all();

        return self::succes($elements, $message, 200, [
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'total' => $page->total(),
            'derniere_page' => $page->lastPage(),
        ] + $meta);
    }

    /**
     * @param  array<string, list<string>>  $erreurs  erreurs de validation, champ => messages
     * @param  array<string, string>  $entetes
     */
    public static function erreur(string $message, int $statut, string $code, array $erreurs = [], mixed $data = null, array $entetes = []): JsonResponse
    {
        $corps = ['success' => false, 'message' => $message, 'data' => self::resoudre($data), 'code' => $code];

        if ($erreurs !== []) {
            $corps['errors'] = $erreurs;
        }

        return self::json($corps, $statut, $entetes);
    }

    private static function resoudre(mixed $data): mixed
    {
        return $data instanceof JsonResource ? $data->resolve() : $data;
    }

    /** @param  array<string, mixed>  $corps  @param  array<string, string>  $entetes */
    private static function json(array $corps, int $statut, array $entetes = []): JsonResponse
    {
        // Les réponses de l'API sont propres à la personne connectée : jamais mises en cache par un intermédiaire.
        return response()->json($corps, $statut, $entetes + ['Cache-Control' => 'no-store, private'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
