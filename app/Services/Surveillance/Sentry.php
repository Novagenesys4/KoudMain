<?php

namespace App\Services\Surveillance;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Envoi des erreurs à Sentry, sans SDK (Packagist n'est pas nécessaire) : ce petit client parle directement à l'API
 * « envelope » de Sentry. Il suffit de renseigner SENTRY_DSN (Sentry > Settings > Projects > Client Keys) ; sans DSN,
 * rien n'est envoyé et rien ne coûte. Pour passer un jour au SDK officiel (sentry/sentry-laravel), seul ce fichier change.
 *
 * Garanties :
 *  - jamais bloquant : 1 s pour se connecter, 2 s au total, et aucune erreur ne remonte à la page ;
 *  - au plus 20 envois par requête (une boucle en erreur n'inonde pas Sentry) ;
 *  - rien de personnel n'est transmis : ni cookies, ni en-têtes, ni corps de formulaire, ni paramètres d'adresse ;
 *    seulement la méthode, le chemin, l'identifiant de la requête et celui de l'utilisateur.
 */
class Sentry
{
    private const MAX_PAR_REQUETE = 20;

    private int $envois = 0;

    /** @return array{cle: string, url: string, dsn: string}|null */
    public function configuration(): ?array
    {
        $dsn = (string) config('koudmain.sentry.dsn');

        // https://<cle_publique>@<hote>/<id_projet>
        if ($dsn === '' || ! preg_match('#^(https?)://([^@:/]+)(?::[^@/]*)?@([^/]+)(/.*)?/(\d+)$#', $dsn, $m)) {
            return null;
        }

        return ['cle' => $m[2], 'url' => sprintf('%s://%s%s/api/%s/envelope/', $m[1], $m[3], $m[4] ?? '', $m[5]), 'dsn' => $dsn];
    }

    public function actif(): bool
    {
        return $this->configuration() !== null;
    }

    public function capturer(Throwable $e): void
    {
        $valeurs = [];

        for ($ex = $e; $ex !== null; $ex = $ex->getPrevious()) {
            $frames = [];
            foreach (array_reverse($ex->getTrace()) as $f) {
                $frames[] = [
                    'filename' => isset($f['file']) ? $this->cheminRelatif($f['file']) : '[interne]',
                    'lineno' => $f['line'] ?? 0,
                    'function' => ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''),
                ];
            }
            $frames[] = ['filename' => $this->cheminRelatif($ex->getFile()), 'lineno' => $ex->getLine(), 'function' => '(lancée ici)'];
            $valeurs[] = ['type' => $ex::class, 'value' => $this->assainir($ex->getMessage()), 'stacktrace' => ['frames' => $frames]];
        }

        $this->envoyer(['level' => 'error', 'exception' => ['values' => array_reverse($valeurs)]]);
    }

    /**
     * Un message d'erreur peut contenir une donnée personnelle (« Key (email)=(a@b.ci) already exists », une requête SQL avec ses
     * valeurs...) : adresses e-mail, valeurs de « Key (...)=(...) » et suites de 6 chiffres ou plus (téléphone, compte) sont masquées.
     */
    public function assainir(string $texte): string
    {
        $texte = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-mail]', $texte) ?? $texte;
        $texte = preg_replace('/Key \(([^)]*)\)=\([^)]*\)/', 'Key ($1)=([masqué])', $texte) ?? $texte;
        $texte = preg_replace('/\d[\d ]{5,}\d/', '[nombre]', $texte) ?? $texte;

        return mb_substr($texte, 0, 500);
    }

    public function message(string $message, string $niveau = 'error', array $extra = []): void
    {
        $this->envoyer(['level' => $niveau === 'critical' ? 'fatal' : $niveau, 'message' => ['formatted' => $this->assainir($message)], 'extra' => $extra]);
    }

    /** @param array<string, mixed> $evenement */
    private function envoyer(array $evenement): void
    {
        $cfg = $this->configuration();

        if ($cfg === null || $this->envois >= self::MAX_PAR_REQUETE) {
            return;
        }

        $this->envois++;

        try {
            $id = bin2hex(random_bytes(16));
            $requete = request();

            $evenement += [
                'event_id' => $id,
                'timestamp' => microtime(true),
                'platform' => 'php',
                'environment' => (string) config('koudmain.sentry.environnement'),
                'release' => (string) config('koudmain.sentry.version'),
                'server_name' => gethostname() ?: 'koudmain',
                'tags' => ['requete' => $requete->attributes->get('requete_id')],
            ];

            if ($requete->user()) {
                $evenement['user'] = ['id' => (string) $requete->user()->id];
            }

            if (! app()->runningInConsole()) {
                $evenement['request'] = ['method' => $requete->method(), 'url' => $requete->url()]; // url() : sans les paramètres
            }

            $corps = json_encode(['event_id' => $id, 'dsn' => $cfg['dsn'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')])."\n"
                .json_encode(['type' => 'event'])."\n"
                .json_encode($evenement, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";

            Http::withBody($corps, 'application/x-sentry-envelope')
                ->withHeaders(['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=koudmain-laravel/1.0, sentry_key='.$cfg['cle']])
                ->connectTimeout(1)->timeout(2)
                ->post($cfg['url']);
        } catch (Throwable) {
            // Le suivi des erreurs ne doit jamais casser l'application.
        }
    }

    private function cheminRelatif(string $chemin): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $chemin);
    }
}
