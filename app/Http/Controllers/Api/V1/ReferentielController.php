<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModePaiement;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\OtpService;
use App\Services\PaiementService;
use App\Support\Referentiel;
use Illuminate\Http\JsonResponse;

/** Les listes publiques qui changent rarement (catégories, zones) et les réglages utiles à l'application. Mises en cache côté serveur. */
class ReferentielController extends Controller
{
    /** Catégories et leurs services (filtres du catalogue, KYC). */
    public function categories(): JsonResponse
    {
        return ApiResponse::succes(Referentiel::categoriesAvecServices()->map(fn ($c) => [
            'id' => $c->id,
            'nom' => $c->nom,
            'services' => $c->services->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all(),
        ])->values()->all());
    }

    /** Villes et quartiers (inscription : quartier obligatoire ; filtre « zone » du catalogue : « v:ID » ou « q:ID »). */
    public function zones(): JsonResponse
    {
        return ApiResponse::succes(Referentiel::villes()->map(fn ($v) => [
            'id' => $v->id,
            'nom' => $v->nom,
            'zone' => 'v:'.$v->id,
            'quartiers' => $v->quartiers->map(fn ($q) => ['id' => $q->id, 'nom' => $q->nom, 'zone' => 'q:'.$q->id])->values()->all(),
        ])->values()->all());
    }

    /** Réglages : limites d'argent, moyens de paiement, fonctionnalités actives. L'application n'écrit aucune de ces valeurs en dur. */
    public function parametres(PaiementService $paiements, OtpService $otp): JsonResponse
    {
        $f = config('koudmain.finance');

        return ApiResponse::succes([
            'devise' => config('koudmain.devise'),
            'recharge' => [
                'active' => $paiements->actif(),
                'simulation' => $paiements->simulation(),
                'min' => (int) $f['recharge_min'],
                'max' => (int) $f['mouvement_max'],
                'methodes' => array_values(array_diff($f['methodes_recharge'], ['Carte bancaire'])),
                'montants_rapides' => $f['montants_rapides'],
            ],
            'retrait' => [
                'min' => (int) $f['retrait_min'],
                'max' => (int) $f['mouvement_max'],
                'methodes' => $f['methodes_retrait'],
            ],
            'commande' => [
                'quantite_max' => (int) $f['quantite_max'],
                'liberation_auto_jours' => (int) $f['liberation_auto_jours'],
                'modes_paiement' => collect([ModePaiement::MobileMoney, ModePaiement::Physique])
                    ->map(fn (ModePaiement $m) => ['code' => $m->value, 'libelle' => $m->libelle()])->all(),
                'reservation_jours' => (int) config('koudmain.reservation.jours'),
                'delai_minimal_heures' => (int) config('koudmain.reservation.delai_minimal_heures'),
            ],
            'sms_actif' => $otp->actif(),
            'otp' => ['longueur' => (int) config('koudmain.api.otp.longueur'), 'renvoi_secondes' => (int) config('koudmain.api.otp.renvoi_secondes')],
            'messagerie' => ['longueur_max' => (int) config('koudmain.messagerie.longueur_max')],
        ]);
    }
}
