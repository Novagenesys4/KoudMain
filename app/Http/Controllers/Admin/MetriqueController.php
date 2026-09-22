<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Metriques\Sante;
use App\Services\Metriques\TableauMetriques;
use App\Services\Taches\Suivi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/metriques : l'activité de la plateforme (inscriptions, commandes, argent) sur 7, 30 ou 90 jours, et la santé du système
 * (base, planificateur, e-mails, paiements, Sentry...). Les chiffres d'activité sont gardés quelques minutes en cache ;
 * la santé est toujours vérifiée en direct. `?actualiser=1` force le recalcul des chiffres.
 */
class MetriqueController extends Controller
{
    public function __invoke(Request $request, TableauMetriques $tableau, Sante $sante, Suivi $suivi): View
    {
        $periodes = config('koudmain.metriques.periodes');
        $jours = (int) $request->query('jours', 30);
        $jours = in_array($jours, $periodes, true) ? $jours : 30;

        $m = $tableau->pour($jours, $request->boolean('actualiser'));
        $controles = $sante->controles();

        return view('admin.metriques', [
            'jours' => $jours,
            'periodes' => $periodes,
            'm' => $m,
            'k' => $m['k'],
            'calculeA' => CarbonImmutable::parse($m['calcule_a'])->setTimezone(config('app.timezone')),
            'controles' => $controles,
            'synthese' => $sante->synthese($controles),
            'taches' => $suivi->toutes()->reject(fn ($t) => $t->nom === Suivi::BATTEMENT),
        ]);
    }
}
