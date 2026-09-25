<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\RefusApi;
use App\Services\OtpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API : les actions qui engagent de l'argent ou un rendez-vous (commander, recharger, retirer, agir sur une commande)
 * exigent un compte vérifié par code (SMS, ou e-mail tant que SMS_DRIVER=email : voir User::compteVerifie).
 * Code « telephone_non_verifie » : l'application ouvre l'écran OTP.
 */
class TelephoneVerifie
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->compteVerifie()) {
            throw new RefusApi(
                OtpService::parEmail()
                    ? 'Vérifiez votre compte pour continuer : un code vous sera envoyé par e-mail.'
                    : 'Vérifiez votre numéro de téléphone pour continuer : un code vous sera envoyé par SMS.',
                403,
                'telephone_non_verifie',
            );
        }

        return $next($request);
    }
}
