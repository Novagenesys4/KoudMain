<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Donne un numéro à chaque requête (et le renvoie dans l'en-tête X-Request-Id) et l'ajoute, avec l'identifiant de la personne
 * connectée, à TOUTES les lignes du journal écrites pendant cette requête : quand un client signale un souci, on retrouve d'un
 * coup tout ce qui s'est passé. Un numéro fourni par le proxy (Render) est repris s'il a une forme sûre.
 */
class ContexteRequete
{
    public function handle(Request $request, Closure $next): Response
    {
        $recu = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $recu) === 1 ? $recu : Str::lower(Str::random(16));

        $request->attributes->set('requete_id', $id);
        Log::shareContext(['requete' => $id, 'utilisateur' => $request->user()?->id]);

        $reponse = $next($request);
        $reponse->headers->set('X-Request-Id', $id);

        return $reponse;
    }
}
