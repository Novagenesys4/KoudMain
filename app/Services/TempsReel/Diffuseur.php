<?php

namespace App\Services\TempsReel;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La « boîte aux lettres » temps réel de chaque utilisateur.
 *
 * Diffuseur::vers($utilisateur, 'message', [...]) ajoute une ligne dans evenements_temps_reel ; le flux (TempsReelController)
 * la lit dans la seconde et l'envoie au navigateur. On passe par la base plutôt que par un serveur de messages : c'est la
 * seule chose que Render + Supabase offrent déjà, et elle survit aux redémarrages (un navigateur qui se reconnecte
 * retrouve ce qu'il a manqué grâce au numéro du dernier événement reçu).
 *
 * Un événement ne doit JAMAIS contenir un secret : il ne porte que ce que l'écran affiche.
 * Un échec de diffusion ne doit jamais casser l'action qui l'a déclenchée (payer, commander...) : on journalise et on continue.
 */
class Diffuseur
{
    /**
     * Ajoute un événement pour un utilisateur (après la validation de la transaction en cours, s'il y en a une).
     *
     * @param  array<string, mixed>  $donnees
     */
    public function vers(User|int $utilisateur, string $type, array $donnees = []): void
    {
        $id = $utilisateur instanceof User ? $utilisateur->id : $utilisateur;

        DB::afterCommit(function () use ($id, $type, $donnees): void {
            try {
                DB::table('evenements_temps_reel')->insert([
                    'user_id' => $id,
                    'type' => $type,
                    'donnees' => json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning('temps_reel.diffusion_echouee', ['utilisateur' => $id, 'type' => $type, 'erreur' => $e->getMessage()]);
            }
        });
    }

    /**
     * @param  iterable<User|int>  $utilisateurs
     * @param  array<string, mixed>  $donnees
     */
    public function versPlusieurs(iterable $utilisateurs, string $type, array $donnees = []): void
    {
        foreach ($utilisateurs as $utilisateur) {
            $this->vers($utilisateur, $type, $donnees);
        }
    }

    /** Numéro du dernier événement de cet utilisateur (0 s'il n'en a jamais eu) : le point de départ d'un nouveau flux. */
    public function dernierId(int $utilisateurId): int
    {
        return (int) DB::table('evenements_temps_reel')->where('user_id', $utilisateurId)->max('id');
    }

    /**
     * Les événements arrivés après le numéro donné, dans l'ordre.
     *
     * @return Collection<int, object{id: int, type: string, donnees: string}>
     */
    public function depuis(int $utilisateurId, int $apresId, int $limite = 100): Collection
    {
        return DB::table('evenements_temps_reel')
            ->where('user_id', $utilisateurId)
            ->where('id', '>', $apresId)
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'type', 'donnees']);
    }

    /** Efface les événements trop vieux : la boîte aux lettres ne grossit pas. @return int nombre de lignes supprimées */
    public function purger(?int $minutes = null): int
    {
        $minutes ??= (int) config('koudmain.temps_reel.conservation_minutes');

        return DB::table('evenements_temps_reel')->where('created_at', '<', now()->subMinutes($minutes))->delete();
    }

    // -------------------------------------------------------------------- Mode

    /**
     * Comment le navigateur doit écouter : « flux » (connexion SSE ouverte, instantané) ou « sondage » (une petite requête toutes
     * les 3 s). Un flux occupe un processus du serveur pendant ~25 s : sur un serveur qui n'en a qu'un (php artisan serve sous
     * Windows, ou sans PHP_CLI_SERVER_WORKERS), il gèlerait toutes les autres pages — d'où le repli automatique.
     *
     * @return 'flux'|'sondage'
     */
    public static function mode(): string
    {
        $voulu = (string) config('koudmain.temps_reel.mode', 'auto');

        if ($voulu === 'flux' || $voulu === 'sondage') {
            return $voulu;
        }

        if (PHP_SAPI === 'cli-server') {
            $ouvriers = (int) getenv('PHP_CLI_SERVER_WORKERS');

            return PHP_OS_FAMILY !== 'Windows' && $ouvriers >= 2 ? 'flux' : 'sondage';
        }

        return 'flux';
    }

    // ---------------------------------------------------------------- Présence

    /** L'utilisateur a un navigateur connecté au flux (ou l'a eu il y a moins d'une minute). Sert à ne pas envoyer d'e-mail à quelqu'un qui lit déjà. */
    public function marquerPresent(int $utilisateurId): void
    {
        try {
            Cache::put("temps_reel.present.$utilisateurId", 1, 60);
        } catch (Throwable) {
            // la présence est une commodité : sans elle, on enverrait simplement un e-mail de plus
        }
    }

    public function estPresent(int $utilisateurId): bool
    {
        try {
            return Cache::has("temps_reel.present.$utilisateurId");
        } catch (Throwable) {
            return false;
        }
    }
}
