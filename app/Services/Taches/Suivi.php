<?php

namespace App\Services\Taches;

use App\Support\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Le carnet des tâches planifiées : pour chacune, quand elle a tourné pour la dernière fois, avec quel résultat et en combien
 * de temps. La page « Métriques et santé » s'en sert pour dire si le planificateur tourne vraiment.
 *
 *   $resume = app(Suivi::class)->suivre('nettoyage', fn () => '12 lignes supprimées');
 *
 * `suivre` attrape toute erreur : elle est notée « erreur » dans le carnet, écrite dans le journal (et envoyée à Sentry),
 * et `null` est renvoyé. Une tâche qui plante ne doit pas arrêter le planificateur ni les autres tâches.
 */
class Suivi
{
    public const BATTEMENT = 'planificateur';

    /** @param callable(): string $travail renvoie un court résumé (« 3 paiements libérés ») */
    public function suivre(string $nom, callable $travail): ?string
    {
        $debut = hrtime(true);

        try {
            $resume = (string) $travail();
            $this->noter($nom, 'ok', $resume, $this->millisecondes($debut));

            return $resume;
        } catch (Throwable $e) {
            Journal::erreur('tache.echec', ['tache' => $nom], $e);
            $this->noter($nom, 'erreur', $e::class.' : '.$e->getMessage(), $this->millisecondes($debut));

            return null;
        }
    }

    /** Le planificateur est vivant : appelé chaque minute par schedule:run / schedule:work. */
    public function battement(): void
    {
        $this->noter(self::BATTEMENT, 'ok', null, 0);
    }

    public function noter(string $nom, string $statut, ?string $resume, int $dureeMs): void
    {
        try {
            DB::statement(
                'insert into taches_planifiees (nom, derniere_execution, statut, resume, duree_ms, executions, echecs, created_at, updated_at)
                 values (?, ?, ?, ?, ?, 1, ?, ?, ?)
                 on conflict (nom) do update set
                    derniere_execution = excluded.derniere_execution, statut = excluded.statut, resume = excluded.resume, duree_ms = excluded.duree_ms,
                    executions = taches_planifiees.executions + 1, echecs = taches_planifiees.echecs + excluded.echecs, updated_at = excluded.updated_at',
                [$nom, $maintenant = now(), $statut, $resume === null ? null : mb_substr($resume, 0, 255), $dureeMs, $statut === 'erreur' ? 1 : 0, $maintenant, $maintenant],
            );
        } catch (Throwable $e) {
            // Le carnet est une commodité : s'il est indisponible, la tâche a tout de même tourné.
            Journal::alerte('tache.carnet_indisponible', ['tache' => $nom, 'message' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, object> les tâches connues, la plus récente d'abord */
    public function toutes(): Collection
    {
        if (! Schema::hasTable('taches_planifiees')) {
            return collect();
        }

        return DB::table('taches_planifiees')->orderByDesc('derniere_execution')->get()->map(function (object $ligne): object {
            $ligne->derniere_execution = $ligne->derniere_execution ? CarbonImmutable::parse($ligne->derniere_execution) : null;

            return $ligne;
        });
    }

    /** Vrai si le planificateur a donné signe de vie récemment. */
    public function planificateurVivant(): bool
    {
        $dernier = DB::table('taches_planifiees')->where('nom', self::BATTEMENT)->value('derniere_execution');

        return $dernier !== null
            && CarbonImmutable::parse($dernier)->greaterThan(now()->subSeconds((int) config('koudmain.taches.battement_max_secondes')));
    }

    private function millisecondes(int $debut): int
    {
        return (int) round((hrtime(true) - $debut) / 1_000_000);
    }
}
