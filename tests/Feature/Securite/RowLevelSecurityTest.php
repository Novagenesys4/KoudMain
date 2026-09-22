<?php

namespace Tests\Feature\Securite;

use App\Support\SecuriteBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Règle 4 : RLS activée sur toutes les tables, sans jamais verrouiller l'application elle-même. */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_toutes_les_tables_du_schema_public_ont_la_rls_apres_les_migrations(): void
    {
        $this->assertSame([], SecuriteBase::sansProtection(), 'Ces tables n\'ont pas la RLS : lancez php artisan koudmain:securiser-base.');
    }

    public function test_une_table_ajoutee_plus_tard_est_protegee_par_la_commande(): void
    {
        DB::statement('create table public.table_future_de_test (id serial primary key)');

        $this->assertContains('table_future_de_test', SecuriteBase::sansProtection());

        $this->artisan('koudmain:securiser-base')->assertSuccessful();

        $this->assertNotContains('table_future_de_test', SecuriteBase::sansProtection());
        $this->artisan('koudmain:securiser-base --verifier')->assertSuccessful();
    }

    public function test_la_commande_de_verification_echoue_quand_une_table_n_est_pas_protegee(): void
    {
        DB::statement('create table public.table_oubliee_de_test (id serial primary key)');

        $this->artisan('koudmain:securiser-base --verifier')->assertFailed();
    }

    public function test_la_commande_est_rejouable_sans_effet(): void
    {
        $this->artisan('koudmain:securiser-base')->assertSuccessful();
        $this->artisan('koudmain:securiser-base')->assertSuccessful();

        $this->assertSame([], SecuriteBase::sansProtection());
    }

    public function test_l_application_lit_et_ecrit_toujours_ses_tables_avec_la_rls_active(): void
    {
        // Le propriétaire des tables contourne la RLS : activer la RLS ne doit rien casser pour Laravel.
        $this->creerQuartier();

        $this->assertSame(1, DB::table('quartiers')->count());
    }

    public function test_aucune_table_n_est_forcee(): void
    {
        // FORCE ROW LEVEL SECURITY s'appliquerait aussi au propriétaire (donc à Laravel) et ferait tomber le site.
        $forcees = DB::select("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' and c.relforcerowsecurity");

        $this->assertSame([], $forcees);
    }
}
