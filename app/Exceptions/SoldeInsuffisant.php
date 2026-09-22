<?php

namespace App\Exceptions;

use App\Support\Format;

class SoldeInsuffisant extends OperationRefusee
{
    public function __construct(public readonly float $requis, public readonly float $disponible)
    {
        parent::__construct('Solde insuffisant : il vous faut '.Format::fcfa($requis).' et vous avez '.Format::fcfa($disponible).'. Rechargez votre wallet puis réessayez.');
    }
}
