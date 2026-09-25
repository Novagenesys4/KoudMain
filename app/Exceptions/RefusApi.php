<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un refus propre à l'API mobile, avec son code HTTP et un code machine stable que l'application peut tester
 * (ex. « telephone_non_verifie » : l'application ouvre l'écran OTP). Le message est écrit pour l'utilisateur.
 */
class RefusApi extends RuntimeException
{
    public function __construct(string $message, public readonly int $statut = 403, public readonly string $codeApi = 'refuse')
    {
        parent::__construct($message);
    }
}
