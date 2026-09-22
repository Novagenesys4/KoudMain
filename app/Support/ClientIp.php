<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Adresse IP du visiteur, utilisée pour limiter les tentatives.
 *
 * Pourquoi ne pas utiliser $request->ip() ? Sur Render, le proxy ajoute l'IP réelle À LA FIN de
 * X-Forwarded-For, mais un visiteur malveillant peut écrire ce qu'il veut AU DÉBUT de l'en-tête.
 * Laravel, quand on lui fait confiance à tous les proxys, lit le début : l'IP serait falsifiable
 * et la limite contournable. On lit donc la dernière entrée (celle du proxy), comme l'ancienne app.
 */
final class ClientIp
{
    public static function resoudre(Request $request): string
    {
        $ip = (string) $request->server('REMOTE_ADDR', '0.0.0.0');

        if (config('koudmain.trust_proxy') && $request->headers->has('X-Forwarded-For')) {
            $liste = array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For')));
            $derniere = end($liste);

            if ($derniere !== false && filter_var($derniere, FILTER_VALIDATE_IP)) {
                $ip = $derniere;
            }
        }

        return substr($ip, 0, 45);
    }
}
