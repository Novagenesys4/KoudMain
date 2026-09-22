<?php

namespace App\Http\Controllers;

use App\Services\TempsReel\Diffuseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le temps réel côté serveur : un flux Server-Sent Events par navigateur (/temps-reel) et, en secours, une simple requête
 * de rattrapage (/temps-reel/sonder) pour les réseaux qui mettent les flux en tampon.
 *
 * Le flux est volontairement court (config koudmain.temps_reel.duree_flux, 25 s) : il envoie ce qui arrive, un signal de vie
 * toutes les quelques secondes, puis se ferme ; le navigateur le rouvre aussitôt avec le numéro du dernier événement reçu.
 * Ainsi un processus du serveur n'est jamais retenu longtemps et rien n'est perdu entre deux flux.
 */
class TempsReelController extends Controller
{
    public function __construct(private readonly Diffuseur $diffuseur)
    {
    }

    public function flux(Request $request): StreamedResponse
    {
        $utilisateurId = $request->user()->id;
        // Sur un serveur qui ne traite qu'une requête à la fois, on refuse le flux (« arret ») : le navigateur passe au sondage.
        $actif = (bool) config('koudmain.temps_reel.actif') && Diffuseur::mode() === 'flux';
        $duree = max(0, (int) config('koudmain.temps_reel.duree_flux'));
        $pause = max(100, (int) config('koudmain.temps_reel.pause_ms')) * 1000;

        // Le dernier événement que le navigateur connaît. Sans lui (première connexion), on part de « maintenant » :
        // il ne doit pas recevoir d'un coup tout ce qui s'est passé avant qu'il ouvre la page.
        $depuis = $request->query('depuis', $request->header('Last-Event-ID'));
        $dernier = is_numeric($depuis) && (int) $depuis >= 0 ? (int) $depuis : $this->diffuseur->dernierId($utilisateurId);

        // La session est déjà lue : on la libère tout de suite pour ne pas la bloquer pendant la durée du flux.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $reponse = new StreamedResponse(function () use ($utilisateurId, $actif, $duree, $pause, $dernier): void {
            ignore_user_abort(false);
            @set_time_limit($duree + 15);

            // Aucune mise en tampon : chaque événement part tout de suite (sauf dans les tests, qui capturent la sortie).
            while (! app()->runningUnitTests() && ob_get_level() > 0) {
                @ob_end_flush();
            }

            // Un flux simple : chaque événement est un message « data: {"type":…,"donnees":…} » (le navigateur les reçoit tous
            // par le même gestionnaire) ; « id: » lui permet de reprendre au bon endroit s'il se reconnecte.
            echo "retry: 1500\n\n";
            // Le message d'accueil donne le numéro de départ : si le navigateur se reconnecte avant d'avoir reçu quoi que ce soit,
            // il reprend exactement là, sans trou.
            echo 'data: '.json_encode(['id' => $dernier, 'type' => $actif ? 'bonjour' : 'arret', 'donnees' => new \stdClass()])."\n\n";
            $this->vider();

            if (! $actif) {
                return;
            }

            $this->diffuseur->marquerPresent($utilisateurId);
            $debut = microtime(true);
            $dernierSignal = microtime(true);

            do {
                foreach ($this->diffuseur->depuis($utilisateurId, $dernier) as $evenement) {
                    $dernier = (int) $evenement->id;
                    echo 'id: '.$evenement->id."\ndata: ".'{"id":'.$evenement->id.',"type":'.json_encode($evenement->type).',"donnees":'.($evenement->donnees ?: '{}')."}\n\n";
                    $dernierSignal = microtime(true);
                }

                // Un signal de vie régulier : il garde la connexion ouverte à travers les proxys et révèle un navigateur parti.
                if (microtime(true) - $dernierSignal >= 10) {
                    echo ": vie\n\n";
                    $dernierSignal = microtime(true);
                    $this->diffuseur->marquerPresent($utilisateurId);
                }

                $this->vider();

                if (connection_aborted() || microtime(true) - $debut >= $duree) {
                    break;
                }

                usleep($pause);
            } while (true);
        });

        $reponse->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $reponse->headers->set('Cache-Control', 'no-cache, no-store, no-transform');
        $reponse->headers->set('X-Accel-Buffering', 'no'); // nginx : ne pas mettre le flux en tampon

        return $reponse;
    }

    /** Rattrapage sans flux longue durée : ce qui est arrivé depuis le numéro donné. */
    public function sonder(Request $request): JsonResponse
    {
        $utilisateurId = $request->user()->id;
        $depuis = $request->query('depuis');
        $dernier = is_numeric($depuis) && (int) $depuis >= 0 ? (int) $depuis : $this->diffuseur->dernierId($utilisateurId);

        $this->diffuseur->marquerPresent($utilisateurId);

        $evenements = $this->diffuseur->depuis($utilisateurId, $dernier)->map(fn ($e) => [
            'id' => (int) $e->id,
            'type' => $e->type,
            'donnees' => json_decode($e->donnees, true) ?? [],
        ])->values();

        return response()->json(['dernier' => $evenements->last()['id'] ?? $dernier, 'evenements' => $evenements], headers: ['Cache-Control' => 'no-store']);
    }

    private function vider(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        @ob_flush();
        flush();
    }
}
