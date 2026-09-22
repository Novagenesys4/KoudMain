<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Une règle métier refuse l'opération (solde insuffisant, mauvais statut, créneau pris...).
 * Le message est écrit POUR l'utilisateur : les contrôleurs l'affichent tel quel.
 */
class OperationRefusee extends RuntimeException
{
}
