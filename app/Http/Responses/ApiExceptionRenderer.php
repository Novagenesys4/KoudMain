<?php

namespace App\Http\Responses;

use App\Exceptions\OperationRefusee;
use App\Exceptions\RefusApi;
use App\Exceptions\SoldeInsuffisant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Toute erreur levée sous /api/* devient une réponse JSON de forme standard (voir ApiResponse), avec le bon code HTTP.
 * Jamais de page HTML, jamais de trace technique : le détail reste dans le journal (et Sentry), retrouvable par X-Request-Id.
 *
 *   400 requete_invalide · 401 non_authentifie · 403 acces_refuse (ou code précis) · 404 introuvable · 405 methode_interdite
 *   422 validation | operation_refusee | solde_insuffisant · 429 trop_de_requetes · 500 erreur_serveur · 503 indisponible
 */
final class ApiExceptionRenderer
{
    public static function rendre(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => ApiResponse::erreur(
                self::premierMessage($e) ?? 'Certaines informations sont invalides.', 422, 'validation', $e->errors(),
            ),
            $e instanceof AuthenticationException => ApiResponse::erreur('Votre session a expiré. Reconnectez-vous.', 401, 'non_authentifie'),
            $e instanceof RefusApi => ApiResponse::erreur($e->getMessage(), $e->statut, $e->codeApi),
            $e instanceof SoldeInsuffisant => ApiResponse::erreur($e->getMessage(), 422, 'solde_insuffisant', data: [
                'requis' => (int) round($e->requis),
                'disponible' => (int) round($e->disponible),
                'manquant' => (int) ceil(max(0, $e->requis - $e->disponible)),
            ]),
            $e instanceof OperationRefusee => ApiResponse::erreur($e->getMessage(), 422, 'operation_refusee'),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::erreur(
                'Vous n\'avez pas accès à cette ressource.', 403, 'acces_refuse',
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::erreur('Ressource introuvable.', 404, 'introuvable'),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::erreur('Méthode non autorisée pour cette adresse.', 405, 'methode_interdite'),
            $e instanceof ThrottleRequestsException => ApiResponse::erreur(
                'Trop de requêtes. Patientez un instant avant de réessayer.', 429, 'trop_de_requetes',
                entetes: array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'])),
            ),
            $e instanceof HttpExceptionInterface => self::http($e),
            default => ApiResponse::erreur('Une erreur inattendue est survenue. Réessayez dans un instant.', 500, 'erreur_serveur'),
        };
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $statut = $e->getStatusCode();

        return match (true) {
            $statut === 503 => ApiResponse::erreur('Le service est momentanément indisponible. Réessayez dans un instant.', 503, 'indisponible'),
            $statut >= 500 => ApiResponse::erreur('Une erreur inattendue est survenue. Réessayez dans un instant.', $statut, 'erreur_serveur'),
            $statut === 401 => ApiResponse::erreur('Votre session a expiré. Reconnectez-vous.', 401, 'non_authentifie'),
            $statut === 403 => ApiResponse::erreur('Vous n\'avez pas accès à cette ressource.', 403, 'acces_refuse'),
            $statut === 404 => ApiResponse::erreur('Ressource introuvable.', 404, 'introuvable'),
            $statut === 413 => ApiResponse::erreur('La requête est trop volumineuse.', 413, 'trop_volumineux'),
            default => ApiResponse::erreur('Requête invalide.', $statut, 'requete_invalide'),
        };
    }

    private static function premierMessage(ValidationException $e): ?string
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return null;
    }
}
