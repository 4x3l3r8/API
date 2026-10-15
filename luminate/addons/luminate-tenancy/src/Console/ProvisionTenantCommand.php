<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Database\AppRoleGuard;
use Luminate\Tenancy\Provisioning\AdminSecret;
use Luminate\Tenancy\Registry\RegistryStore;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * Ensure a tenant's baseline data exists: roles, permissions, first admin, Passport personal-access client.
 * Idempotent (ensure-semantics, contract §C). Reads placement/status from the local replica (contract §A.2).
 *
 * MUST run as the RLS-bound app role, never the owner: the stock PermissionsSeeder only creates the first admin when
 * `users` is empty, and a role that bypasses RLS would see other tenants' users and skip every tenant after the first.
 */
final class ProvisionTenantCommand extends Command
{
    protected $signature = 'luminate:tenant:provision
        {tid? : tenant id from the replica snapshot}
        {--all : every tenant in the replica whose status is provisioning or active}';
    protected $description = 'Seed roles, permissions, the machine admin (WITHOUT a usable password) and the Passport clients for a tenant.';

    public function handle(TenantReplica $replica, TenantRuntime $runtime, AppRoleGuard $roleGuard): int
    {
        $roleGuard->assertSafe();
        $this->assertRlsBound();

        $tids = $this->option('all') ? $replica->ids() : array_filter([(string) $this->argument('tid')]);
        if ($tids === []) {
            $this->error('Give a tenant id or --all.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($tids as $tid) {
            if ($this->option('all') && !in_array($replica->status($tid), ['provisioning', 'active'], true)) {
                $this->line("skip {$tid} (status {$replica->status($tid)})");
                continue;
            }
            try {
                // long-lived process switching tenants: also drop in-memory permission state between tenants
                $runtime->activate($replica->resolve($tid, ['provisioning', 'active']), longLivedProcess: true);
                $this->provision($tid);
                $this->recordProvisioned($tid);
            } catch (ContextRejected $e) {
                $this->error("{$tid}: refused ({$e->code()})");
                $failed++;
            } finally {
                $runtime->deactivate(longLivedProcess: true);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function withThrowawayAdminPassword(callable $seed): void
    {
        $previous = ['env' => $_ENV['INITIAL_USER_PASSWORD'] ?? null, 'server' => $_SERVER['INITIAL_USER_PASSWORD'] ?? null, 'getenv' => getenv('INITIAL_USER_PASSWORD')];
        $throwaway = AdminSecret::generate();
        $_ENV['INITIAL_USER_PASSWORD'] = $_SERVER['INITIAL_USER_PASSWORD'] = $throwaway;
        putenv('INITIAL_USER_PASSWORD=' . $throwaway);
        try {
            $seed();
        } finally {
            if ($previous['env'] === null) {
                unset($_ENV['INITIAL_USER_PASSWORD']);
            } else {
                $_ENV['INITIAL_USER_PASSWORD'] = $previous['env'];
            }
            if ($previous['server'] === null) {
                unset($_SERVER['INITIAL_USER_PASSWORD']);
            } else {
                $_SERVER['INITIAL_USER_PASSWORD'] = $previous['server'];
            }
            putenv($previous['getenv'] === false ? 'INITIAL_USER_PASSWORD' : 'INITIAL_USER_PASSWORD=' . $previous['getenv']);
        }
    }

    /** Tell the registry replica this tenant's baseline exists (Luminate reads it before flipping the tenant to active). */
    private function recordProvisioned(string $tid): void
    {
        if (config('luminate-tenancy.replica_driver') === 'database') {
            app(RegistryStore::class)->markProvisioned($tid, time());
        }
    }

    private function provision(string $tid): void
    {
        $this->line("provisioning {$tid}");

        // The stock seeder creates the first admin of an empty tenant from INITIAL_USER_PASSWORD. That is the tenant's MACHINE admin (contract
        // A.2.2): it must not start with any password anyone knows, so the seeder is given a throwaway that is never stored or shown, and the real
        // credential is issued later by Luminate over the signed channel (AdminCredential). The variable is set in every place env() may read
        // it from and removed again, so a shared value can never leak in from the process environment either.
        $this->withThrowawayAdminPassword(static fn () => Artisan::call('db:seed', ['--class' => 'PermissionsSeeder', '--force' => true, '--no-interaction' => true]));

        // `escolalms/core`'s install_passport migration runs `passport:install` once, tenant-less; each tenant needs its own
        // two clients. Explicit tenant filter: never rely on RLS alone for an idempotency check.
        $app = (string) config('app.name');
        $personal = "{$app} Personal Access Client";
        $password = "{$app} Password Grant Client";
        if (DB::table('oauth_clients')->where('tenant_id', $tid)->where('name', $personal)->count() === 0) {
            Artisan::call('passport:client', ['--personal' => true, '--name' => $personal, '--no-interaction' => true]);
        }
        if (DB::table('oauth_clients')->where('tenant_id', $tid)->where('name', $password)->count() === 0) {
            Artisan::call('passport:client', ['--password' => true, '--name' => $password, '--provider' => 'users', '--no-interaction' => true]);
        }

        // Reference rows that Wellms migrations insert without a tenant (so no tenant could see them).
        foreach ((array) config('luminate-tenancy.reference_rows', []) as $table => $rows) {
            foreach ($rows as $row) {
                $exists = DB::table($table)->where('tenant_id', $tid);
                foreach ($row['_key'] as $col) {
                    $exists->where($col, $row[$col]);
                }
                if (!$exists->exists()) {
                    DB::table($table)->insert(array_merge(array_diff_key($row, ['_key' => 1]), ['created_at' => now(), 'updated_at' => now()]));
                }
            }
        }

        $this->info(sprintf(
            '%s ready (machine admin has no credential until Luminate requests one): %d users, %d roles, %d permissions, %d oauth clients, %d questionnaire types',
            $tid,
            DB::table('users')->where('tenant_id', $tid)->count(),
            DB::table('roles')->where('tenant_id', $tid)->count(),
            DB::table('permissions')->where('tenant_id', $tid)->count(),
            DB::table('oauth_clients')->where('tenant_id', $tid)->count(),
            DB::table('questionnaire_model_types')->where('tenant_id', $tid)->count(),
        ));
    }

    private function assertRlsBound(): void
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        if ($role->rolsuper || $role->rolbypassrls) {
            throw new \RuntimeException("Refusing to provision as '{$role->name}': it bypasses row-level security. Use the app role.");
        }
        // the owner role is not BYPASSRLS but does bypass RLS on tables it owns (RLS is ENABLEd, not FORCEd)
        $owns = DB::selectOne("select count(*) as n from pg_tables where schemaname = 'public' and tablename = 'users' and tableowner = current_user");
        if ((int) $owns->n > 0) {
            throw new \RuntimeException("Refusing to provision as '{$role->name}': it owns the tables, so RLS does not apply to it. Use the app role.");
        }
    }
}
