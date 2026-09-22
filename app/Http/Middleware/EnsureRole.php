<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un rôle : Route::middleware('role:admin'), 'role:prestataire', 'role:client'
 * ou plusieurs à la fois ('role:client,prestataire' : l'un OU l'autre).
 * Remplace requireAdmin() et co. de l'ancien config.php.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('connexion');
        }

        foreach ($roles as $role) {
            if ($user->aLeRole($role)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
