<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Règle 4 : Row Level Security (RLS) sur TOUTES les tables du schéma « public ».
 *
 * Pourquoi : Supabase expose par défaut une API REST (PostgREST) qui donne accès au schéma « public » à toute personne qui possède
 * la clé publique « anon » (visible dans n'importe quelle page qui l'utiliserait). Sans RLS, cette clé permettrait de lire et
 * modifier les tables. KoudMain n'utilise PAS cette API : Laravel se connecte en direct (rôle « postgres », qui possède les tables
 * et contourne la RLS). Activer la RLS SANS aucune politique = « refus total » pour les rôles « anon » et « authenticated »,
 * et aucun effet pour Laravel. On retire en plus leurs droits sur les tables (double barrière).
 *
 * Choix de conception :
 *  - jamais de FORCE ROW LEVEL SECURITY (elle s'appliquerait aussi au propriétaire, donc à Laravel : le site tomberait) ;
 *  - seules les tables que le rôle courant possède sont modifiées : c'est exactement l'ensemble sur lequel il contourne la RLS,
 *    donc l'application ne peut pas se verrouiller elle-même dehors ;
 *  - idempotent : rejouable à chaque déploiement (les futures tables sont protégées dès le déploiement suivant) ;
 *  - jamais bloquant : un droit manquant est signalé, il ne fait pas échouer le démarrage.
 */
class SecuriteBase
{
    /** Rôles de l'API Supabase : ils n'ont rien à faire dans nos tables. */
    private const ROLES_API = ['anon', 'authenticated'];

    /**
     * Active la RLS sur les tables du schéma « public » qui ne l'ont pas encore, et retire les droits des rôles d'API.
     *
     * @return array{actif: bool, activees: list<string>, sans_droit: list<string>, roles_revoques: list<string>}
     */
    public static function appliquer(): array
    {
        $resultat = ['actif' => false, 'activees' => [], 'sans_droit' => [], 'roles_revoques' => []];

        if (DB::getDriverName() !== 'pgsql') {
            return $resultat;
        }

        $resultat['actif'] = true;

        foreach (self::tables() as $table) {
            if ($table->rls) {
                continue;
            }

            if (! $table->possede) {
                $resultat['sans_droit'][] = $table->nom;

                continue;
            }

            DB::statement('ALTER TABLE public.'.self::identifiant($table->nom).' ENABLE ROW LEVEL SECURITY');
            $resultat['activees'][] = $table->nom;
        }

        foreach (self::ROLES_API as $role) {
            if (! DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role])) {
                continue;
            }

            $id = self::identifiant($role);

            try {
                DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM $id");
                DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM $id");
                DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM $id");
                DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM $id");
                $resultat['roles_revoques'][] = $role;
            } catch (\Throwable) {
                // Droit insuffisant pour retirer les accès de ce rôle : la RLS reste la barrière principale.
            }
        }

        return $resultat;
    }

    /**
     * Les tables du schéma « public » (hors partitions) dont la RLS n'est PAS active. Doit toujours être vide après appliquer(),
     * sauf tables que le rôle courant ne possède pas.
     *
     * @return list<string>
     */
    public static function sansProtection(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        return array_values(array_map(fn ($t) => $t->nom, array_filter(self::tables(), fn ($t) => ! $t->rls)));
    }

    /** @return list<object{nom: string, rls: bool, possede: bool}> */
    private static function tables(): array
    {
        $lignes = DB::select(<<<'SQL'
            select c.relname as nom,
                   c.relrowsecurity as rls,
                   pg_has_role(current_user, c.relowner, 'USAGE') as possede
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relispartition
            order by c.relname
        SQL);

        return array_map(fn ($l) => (object) ['nom' => (string) $l->nom, 'rls' => (bool) $l->rls, 'possede' => (bool) $l->possede], $lignes);
    }

    private static function identifiant(string $nom): string
    {
        return '"'.str_replace('"', '""', $nom).'"';
    }
}
