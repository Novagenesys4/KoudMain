<?php

namespace App\Support;

/** 30 -> « 30 min », 90 -> « 1 h 30 », 1440 -> « 1 jour ». */
final class Duree
{
    public static function libelle(?int $minutes): ?string
    {
        if ($minutes === null || $minutes < 1) {
            return null;
        }

        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            $jours = intdiv($minutes, 1440);

            return $jours === 1 ? '1 jour' : "$jours jours";
        }

        if ($minutes < 60) {
            return "$minutes min";
        }

        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        return $reste === 0 ? "$heures h" : sprintf('%d h %02d', $heures, $reste);
    }
}
