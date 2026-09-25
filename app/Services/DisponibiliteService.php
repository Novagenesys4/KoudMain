<?php

namespace App\Services;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\Disponibilite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Quand un prestataire est-il libre ? Deux sources :
 *  - ses HORAIRES hebdomadaires (table disponibilites) : s'il n'en a pas indiqué, on propose la plage par défaut (07 h - 21 h) ;
 *  - ses commandes ACCEPTÉES ou EN COURS, qui occupent leur créneau (une commande « en attente » ne réserve rien :
 *    c'est le prestataire qui décide de l'accepter).
 */
class DisponibiliteService
{
    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /** Plage proposée tous les jours tant que le prestataire n'a indiqué aucun horaire. */
    public const PAR_DEFAUT = ['07:00', '21:00'];

    /**
     * Les plages d'ouverture, jour par jour : [1 => [['08:00', '12:00'], ['14:00', '18:00']], ...]
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function horaires(User $prestataire): array
    {
        $plages = [];

        foreach (Disponibilite::query()->where('user_id', $prestataire->id)->orderBy('jour')->orderBy('heure_debut')->get() as $ligne) {
            $plages[(int) $ligne->jour][] = [substr((string) $ligne->heure_debut, 0, 5), substr((string) $ligne->heure_fin, 0, 5)];
        }

        return $plages;
    }

    public function aDesHoraires(User $prestataire): bool
    {
        return Disponibilite::query()->where('user_id', $prestataire->id)->exists();
    }

    /**
     * Remplace tous les horaires du prestataire (le formulaire envoie la semaine entière).
     *
     * @param  array<int, list<array{0: string, 1: string}>>  $semaine  jour ISO => plages [début, fin]
     */
    public function enregistrer(User $prestataire, array $semaine): void
    {
        DB::transaction(function () use ($prestataire, $semaine): void {
            Disponibilite::query()->where('user_id', $prestataire->id)->delete();

            foreach ($semaine as $jour => $plages) {
                foreach ($plages as [$debut, $fin]) {
                    $ligne = new Disponibilite(['jour' => $jour, 'heure_debut' => $debut, 'heure_fin' => $fin]);
                    $ligne->forceFill(['user_id' => $prestataire->id])->save();
                }
            }
        });
    }

    /**
     * Les jours où le prestataire a au moins un créneau libre, avec leurs heures de début.
     *
     * @return list<array{cle: string, long: string, court: string, creneaux: list<string>}>
     */
    public function creneaux(User $prestataire, int $dureeMinutes, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $jours = (int) config('koudmain.reservation.jours');
        $pas = (int) config('koudmain.reservation.pas_minutes');
        $auPlusTot = $maintenant->addHours((int) config('koudmain.reservation.delai_minimal_heures'));

        $horaires = $this->horaires($prestataire);
        $aDesHoraires = $horaires !== [];
        $occupes = $this->occupes($prestataire, $maintenant->startOfDay(), $maintenant->addDays($jours + 1)->startOfDay());
        $resultat = [];

        for ($i = 0; $i <= $jours; $i++) {
            $jour = $maintenant->startOfDay()->addDays($i);
            $plages = $aDesHoraires ? ($horaires[$jour->dayOfWeekIso] ?? []) : [self::PAR_DEFAUT];
            $heures = [];

            foreach ($plages as [$debut, $fin]) {
                $ouverture = $jour->setTimeFromTimeString($debut);
                $fermeture = $jour->setTimeFromTimeString($fin);
                // Une prestation plus longue que la plage (garde à la journée...) commence à l'ouverture : on ne la coupe pas.
                $tient = min($dureeMinutes, (int) $ouverture->diffInMinutes($fermeture));

                for ($t = $ouverture; $t->addMinutes($tient) <= $fermeture; $t = $t->addMinutes($pas)) {
                    if ($t >= $auPlusTot && ! $this->chevauche($occupes, $t, $dureeMinutes)) {
                        $heures[] = $t->format('H:i');
                    }
                }
            }

            if ($heures !== []) {
                $resultat[] = [
                    'cle' => $jour->format('Y-m-d'),
                    'long' => $jour->translatedFormat('l j F'),
                    'court' => $jour->translatedFormat('D j'),
                    'creneaux' => array_values(array_unique($heures)),
                ];
            }
        }

        return $resultat;
    }

    /**
     * Ce créneau précis est-il possible ? @return string|null le motif du refus (null = libre)
     */
    public function verifier(User $prestataire, CarbonInterface $debut, int $dureeMinutes, ?int $sauf = null): ?string
    {
        $debut = CarbonImmutable::instance($debut);
        $maintenant = CarbonImmutable::now();
        $pas = (int) config('koudmain.reservation.pas_minutes');

        if ($debut < $maintenant->addHours((int) config('koudmain.reservation.delai_minimal_heures'))) {
            return 'Ce créneau est trop proche : réservez au moins '.config('koudmain.reservation.delai_minimal_heures').' h à l\'avance.';
        }

        if ($debut > $maintenant->addDays((int) config('koudmain.reservation.jours') + 1)->startOfDay()) {
            return 'On ne peut réserver que '.config('koudmain.reservation.jours').' jours à l\'avance.';
        }

        if ($debut->minute % $pas !== 0 || $debut->second !== 0) {
            return 'Choisissez une heure ronde (par exemple 09:00 ou 09:30).';
        }

        $horaires = $this->horaires($prestataire);
        $plages = $horaires !== [] ? ($horaires[$debut->dayOfWeekIso] ?? []) : [self::PAR_DEFAUT];
        $dansUnePlage = false;

        foreach ($plages as [$ouvre, $ferme]) {
            $ouverture = $debut->setTimeFromTimeString($ouvre);
            $fermeture = $debut->setTimeFromTimeString($ferme);
            $tient = min($dureeMinutes, (int) $ouverture->diffInMinutes($fermeture));

            if ($debut >= $ouverture && $debut->addMinutes($tient) <= $fermeture) {
                $dansUnePlage = true;
            }
        }

        if (! $dansUnePlage) {
            return 'Le prestataire n\'est pas disponible à ce moment-là.';
        }

        if ($this->estOccupe($prestataire, $debut, $dureeMinutes, $sauf)) {
            return 'Ce créneau est déjà pris par une autre commande.';
        }

        return null;
    }

    /** Une commande acceptée ou en cours occupe-t-elle déjà ce créneau ? ($sauf : la commande que l'on est en train de traiter) */
    public function estOccupe(User $prestataire, CarbonInterface $debut, int $dureeMinutes, ?int $sauf = null): bool
    {
        $debut = CarbonImmutable::instance($debut);

        return $this->chevauche($this->occupes($prestataire, $debut->startOfDay()->subDay(), $debut->addDays(2), $sauf), $debut, $dureeMinutes);
    }

    /**
     * Les créneaux occupés (commandes acceptées ou en cours) dans une période.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function occupes(User $prestataire, CarbonInterface $du, CarbonInterface $au, ?int $sauf = null): array
    {
        return Commande::query()
            ->where('prestataire_id', $prestataire->id)
            ->whereIn('statut', [StatutCommande::Acceptee->value, StatutCommande::EnCours->value])
            ->whereNotNull('date_souhaitee')
            ->where('date_souhaitee', '>=', $du->copy()->subDay())
            ->where('date_souhaitee', '<', $au)
            ->when($sauf !== null, fn ($q) => $q->where('id', '!=', $sauf))
            ->get(['id', 'date_souhaitee', 'duree_minutes'])
            ->map(fn (Commande $c) => [
                CarbonImmutable::instance($c->date_souhaitee),
                CarbonImmutable::instance($c->date_souhaitee)->addMinutes($c->duree_minutes ?? 60),
            ])
            ->all();
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $occupes */
    private function chevauche(array $occupes, CarbonImmutable $debut, int $dureeMinutes): bool
    {
        $fin = $debut->addMinutes($dureeMinutes);

        foreach ($occupes as [$deb, $fi]) {
            if ($debut < $fi && $fin > $deb) {
                return true;
            }
        }

        return false;
    }
}
