<?php

namespace App\Http\Controllers\Prestataire;

use App\Http\Controllers\Controller;
use App\Services\DisponibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** /prestataire/disponibilites : les horaires d'ouverture de la semaine (trois plages possibles par jour, comme l'application). */
class DisponibiliteController extends Controller
{
    private const PLAGES_PAR_JOUR = \App\Http\Controllers\Api\V1\PrestataireController::PLAGES_PAR_JOUR;

    public function edit(Request $request, DisponibiliteService $service): View
    {
        $horaires = $service->horaires($request->user());

        // Une heure toutes les 30 minutes (05 h – 23 h 30) ; les heures déjà enregistrées y sont ajoutées si elles sortent de la grille.
        $heures = collect(range(5 * 2, 23 * 2 + 1))->map(fn (int $demi) => sprintf('%02d:%02d', intdiv($demi, 2), ($demi % 2) * 30));
        $enregistrees = collect($horaires)->flatten()->filter(fn ($h) => is_string($h) && preg_match('/^\d{2}:\d{2}$/', $h));

        return view('prestataire.disponibilites', [
            'heures' => $heures->merge($enregistrees)->unique()->sort()->values()->all(),
            'jours' => DisponibiliteService::JOURS,
            'horaires' => $horaires,
            'plagesParJour' => self::PLAGES_PAR_JOUR,
            'defini' => $horaires !== [],
        ]);
    }

    public function update(Request $request, DisponibiliteService $service): RedirectResponse
    {
        $saisie = $request->input('jours', []);
        $semaine = [];
        $erreurs = [];

        foreach (DisponibiliteService::JOURS as $numero => $nom) {
            $plages = [];

            for ($i = 0; $i < self::PLAGES_PAR_JOUR; $i++) {
                $debut = trim((string) data_get($saisie, "$numero.$i.debut", ''));
                $fin = trim((string) data_get($saisie, "$numero.$i.fin", ''));

                if ($debut === '' && $fin === '') {
                    continue; // plage non utilisée
                }

                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $debut) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $fin)) {
                    $erreurs[] = "$nom : indiquez une heure de début ET une heure de fin (par exemple 08:00 et 12:00).";
                    continue;
                }

                if ($fin <= $debut) {
                    $erreurs[] = "$nom : l'heure de fin ($fin) doit être après l'heure de début ($debut).";
                    continue;
                }

                $plages[] = [$debut, $fin];
            }

            usort($plages, fn ($a, $b) => strcmp($a[0], $b[0]));

            for ($i = 1; $i < count($plages); $i++) {
                if ($plages[$i][0] < $plages[$i - 1][1]) {
                    $erreurs[] = "$nom : deux plages horaires se chevauchent.";
                    break;
                }
            }

            if ($plages !== []) {
                $semaine[$numero] = $plages;
            }
        }

        if ($erreurs !== []) {
            return back()->withInput()->withErrors(['horaires' => $erreurs]);
        }

        $service->enregistrer($request->user(), $semaine);

        return redirect()->route('prestataire.disponibilites')->with('succes', $semaine === []
            ? 'Horaires effacés : les clients peuvent réserver entre 07:00 et 21:00, tous les jours.'
            : 'Horaires enregistrés. Les clients ne peuvent réserver que pendant ces plages.');
    }
}
