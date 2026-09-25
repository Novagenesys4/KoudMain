<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\DisponibiliteService;
use App\Services\TableauPrestataireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Espace prestataire de l'application (Phase 9, écrans 16 et 18 du prototype). Réservé aux prestataires VALIDÉS
 * (middleware api.role:prestataire). Les commandes reçues passent par GET /commandes et POST /commandes/{id}/actions/…
 *
 *   GET /prestataire/tableau         : revenus du mois, 7 barres de la semaine, séquestre, note, nouvelles demandes ;
 *   GET /prestataire/disponibilites  : la semaine type (plages d'ouverture jour par jour) ;
 *   PUT /prestataire/disponibilites  : la remplacer entièrement (numéro vérifié exigé : elle décide des rendez-vous).
 *
 * Les disponibilités sont les MÊMES que celles de la page « Mes horaires » du site (table disponibilites, DisponibiliteService) :
 * ce qui est enregistré ici s'applique aussitôt aux créneaux proposés aux clients.
 */
class PrestataireController extends Controller
{
    /** Plages d'ouverture par jour : 3 (matin, après-midi, soir de la grille du prototype). Même limite que le site. */
    public const PLAGES_PAR_JOUR = 3;

    public function tableau(Request $request, TableauPrestataireService $tableau): JsonResponse
    {
        return ApiResponse::succes($tableau->pour($this->moi($request)));
    }

    public function disponibilites(Request $request, DisponibiliteService $service): JsonResponse
    {
        return ApiResponse::succes($this->semaine($service, $this->moi($request)));
    }

    /**
     * Corps : { "jours": { "1": [["08:00", "12:00"], ["13:00", "17:00"]], "3": [...], ... } } (1 = lundi … 7 = dimanche).
     * Un jour absent (ou vide) est fermé. Au moins une plage : sans aucune plage, le serveur ouvrirait tous les jours
     * de 07:00 à 21:00 (règle du site), l'inverse de ce qu'un prestataire attend en « fermant tout ».
     */
    public function enregistrerDisponibilites(Request $request, DisponibiliteService $service): JsonResponse
    {
        $request->validate(['jours' => ['present', 'array']], ['jours.*' => 'Indiquez vos plages d\'ouverture.']);

        $saisie = $request->input('jours', []);
        $semaine = [];
        $erreurs = [];

        foreach (array_keys($saisie) as $cle) {
            if (! ctype_digit((string) $cle) || (int) $cle < 1 || (int) $cle > 7) {
                $erreurs['jours'][] = 'Jour inconnu : utilisez 1 (lundi) à 7 (dimanche).';
            }
        }

        foreach (DisponibiliteService::JOURS as $numero => $nom) {
            $plages = $saisie[$numero] ?? $saisie[(string) $numero] ?? [];

            if (! is_array($plages)) {
                $erreurs["jours.$numero"][] = "$nom : format de plages invalide.";

                continue;
            }

            if (count($plages) > self::PLAGES_PAR_JOUR) {
                $erreurs["jours.$numero"][] = "$nom : ".self::PLAGES_PAR_JOUR.' plages au maximum.';

                continue;
            }

            $valides = [];
            foreach (array_values($plages) as $plage) {
                $debut = is_array($plage) ? ($plage[0] ?? $plage['debut'] ?? null) : null;
                $fin = is_array($plage) ? ($plage[1] ?? $plage['fin'] ?? null) : null;

                if (! is_string($debut) || ! is_string($fin) || ! $this->heure($debut) || ! $this->heure($fin)) {
                    $erreurs["jours.$numero"][] = "$nom : indiquez une heure de début et une heure de fin (par exemple 08:00 et 12:00).";

                    continue;
                }

                if ($fin <= $debut) {
                    $erreurs["jours.$numero"][] = "$nom : l'heure de fin ($fin) doit être après l'heure de début ($debut).";

                    continue;
                }

                $valides[] = [$debut, $fin];
            }

            usort($valides, fn ($a, $b) => strcmp($a[0], $b[0]));

            for ($i = 1; $i < count($valides); $i++) {
                if ($valides[$i][0] < $valides[$i - 1][1]) {
                    $erreurs["jours.$numero"][] = "$nom : deux plages se chevauchent.";

                    break;
                }
            }

            if ($valides !== []) {
                $semaine[$numero] = $valides;
            }
        }

        if ($erreurs === [] && $semaine === []) {
            $erreurs['jours'][] = 'Ouvrez au moins un créneau pour recevoir des réservations.';
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        $moi = $this->moi($request);
        $service->enregistrer($moi, $semaine);

        return ApiResponse::succes($this->semaine($service, $moi), 'Disponibilités enregistrées');
    }

    /** @return array<string, mixed> */
    private function semaine(DisponibiliteService $service, User $prestataire): array
    {
        $horaires = $service->horaires($prestataire);

        return [
            'defini' => $horaires !== [],
            // Sans aucune plage enregistrée, les clients peuvent réserver tous les jours dans cette plage.
            'par_defaut' => ['debut' => DisponibiliteService::PAR_DEFAUT[0], 'fin' => DisponibiliteService::PAR_DEFAUT[1]],
            'plages_max' => self::PLAGES_PAR_JOUR,
            'jours' => array_map(fn (int $numero, string $nom) => [
                'jour' => $numero,
                'nom' => $nom,
                'plages' => array_map(fn (array $p) => ['debut' => $p[0], 'fin' => $p[1]], $horaires[$numero] ?? []),
            ], array_keys(DisponibiliteService::JOURS), DisponibiliteService::JOURS),
        ];
    }

    private function heure(string $valeur): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur);
    }

    private function moi(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
