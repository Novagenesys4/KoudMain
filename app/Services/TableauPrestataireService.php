<?php

namespace App\Services;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Escrow;
use App\Models\User;
use App\Support\Espace\Compteurs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Les chiffres du tableau de bord prestataire de l'application (écran 16 du prototype) :
 *  - revenus du mois = séquestres LIBÉRÉS ce mois-ci (l'argent réellement versé sur le wallet), comparés au mois précédent ;
 *  - les 7 barres = revenus libérés chaque jour de la semaine en cours (lundi → dimanche) ;
 *  - « En séquestre » = argent payé par les clients, pas encore libéré (bloqué ou en litige) ;
 *  - note moyenne et nombre d'avis (même calcul que le tableau de bord du site).
 *
 * Une commande réglée en main propre (paiement « physique ») n'a pas de séquestre : elle n'entre pas dans ces revenus,
 * comme sur le site.
 */
class TableauPrestataireService
{
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const JOURS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

    /** @return array<string, mixed> */
    public function pour(User $prestataire, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $debutMois = $maintenant->startOfMonth();
        $debutMoisPrecedent = $debutMois->subMonthNoOverflow();

        $revenusMois = $this->liberes($prestataire, $debutMois, $maintenant->endOfMonth());
        $revenusPrecedent = $this->liberes($prestataire, $debutMoisPrecedent, $debutMois->subSecond());

        $sequestre = Escrow::query()->where('prestataire_id', $prestataire->id)
            ->whereIn('statut', [Escrow::BLOQUE, Escrow::LITIGE])
            ->toBase()->selectRaw('coalesce(sum(montant), 0) as total, count(*) as nombre')->first();

        $aValider = Commande::query()->where('prestataire_id', $prestataire->id)
            ->where('statut', StatutCommande::Terminee->value)->whereNull('validee_client_at')->count();

        $notes = DB::table('avis')
            ->join('prestations', 'prestations.id', '=', 'avis.prestation_id')
            ->where('prestations.prestataire_id', $prestataire->id)
            ->selectRaw('ROUND(AVG(avis.note)::numeric, 1) as moyenne, count(*) as total')
            ->first();

        return [
            'revenus' => [
                'mois' => self::MOIS[$maintenant->month - 1],
                'mois_precedent' => self::MOIS[$debutMoisPrecedent->month - 1],
                'montant' => $revenusMois,
                'montant_mois_precedent' => $revenusPrecedent,
                // Null quand le mois précédent est à 0 : un pourcentage n'aurait pas de sens.
                'variation_pourcent' => $revenusPrecedent > 0 ? (int) round(($revenusMois - $revenusPrecedent) / $revenusPrecedent * 100) : null,
            ],
            'semaine' => $this->semaine($prestataire, $maintenant),
            'sequestre' => [
                'montant' => (int) round((float) $sequestre->total),
                'commandes' => (int) $sequestre->nombre,
                'a_valider' => $aValider,
            ],
            'note' => [
                'moyenne' => (int) $notes->total > 0 ? (float) $notes->moyenne : null,
                'avis' => (int) $notes->total,
            ],
            'nouvelles_demandes' => Compteurs::commandesEnAttentePrestataire($prestataire),
        ];
    }

    /** Somme (entier FCFA) des séquestres libérés au prestataire entre deux instants. */
    private function liberes(User $prestataire, CarbonImmutable $du, CarbonImmutable $au): int
    {
        return (int) round((float) Escrow::query()->where('prestataire_id', $prestataire->id)
            ->where('statut', Escrow::LIBERE)->whereBetween('libere_at', [$du, $au])->sum('montant'));
    }

    /**
     * Les 7 jours de la semaine en cours (lundi → dimanche), avec les revenus libérés chaque jour.
     *
     * @return list<array{date: string, libelle: string, montant: int, aujourdhui: bool}>
     */
    private function semaine(User $prestataire, CarbonImmutable $maintenant): array
    {
        $lundi = $maintenant->startOfWeek(CarbonImmutable::MONDAY);
        $parJour = [];

        Escrow::query()->where('prestataire_id', $prestataire->id)->where('statut', Escrow::LIBERE)
            ->whereBetween('libere_at', [$lundi, $lundi->addDays(7)->subSecond()])
            ->get(['montant', 'libere_at'])
            ->each(function (Escrow $e) use (&$parJour): void {
                $cle = $e->libere_at->format('Y-m-d');
                $parJour[$cle] = ($parJour[$cle] ?? 0) + (float) $e->montant;
            });

        $jours = [];
        for ($i = 0; $i < 7; $i++) {
            $jour = $lundi->addDays($i);
            $jours[] = [
                'date' => $jour->format('Y-m-d'),
                'libelle' => self::JOURS[$i],
                'montant' => (int) round($parJour[$jour->format('Y-m-d')] ?? 0),
                'aujourdhui' => $jour->isSameDay($maintenant),
            ];
        }

        return $jours;
    }
}
