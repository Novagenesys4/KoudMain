<?php

namespace App\Services\Media;

use RuntimeException;

/**
 * Une image refusée. Le message est écrit pour l'utilisateur (en français) : on l'affiche tel quel sous le champ.
 */
class ImageInvalide extends RuntimeException
{
}
