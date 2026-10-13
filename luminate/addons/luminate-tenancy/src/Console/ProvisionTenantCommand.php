<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Luminate\Tenancy\Context\CurrentTenant;
use Luminate\Tenancy\Context\ContextRejected;
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
        {--all : every tenant in the replica whose status is provisioning or active}
        {--admin-email= : override the admin e-mail for this tenant}
        {--admin-password= : override the admin password for this tenant}';
    protected $description = 'Seed roles, permissions, the first admin and the Passport personal client for a tenant.';

    public function handle(TenantReplica $replica, CurrentTenant $current): int
    {
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
                $current->set($replica->resolve($tid, ['provisioning', 'active']));
                $this->provision($tid);
            } catch (ContextRejected $e) {
                $this->error("{$tid}: refused ({$e->code()})");
                $failed++;
            } finally {
                $current->clear();
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function provision(string $tid): void
    {
        $this->line("provisioning {$tid}");

        Artisan::call('db:seed', ['--class' => 'PermissionsSeeder', '--force' => true, '--no-interaction' => true]);

        // the seeder creates exactly one user (the first admin) per empty tenant; overrides apply to the lowest id
        $adminId = DB::table('users')->where('tenant_id', $tid)->orderBy('id')->value('id');
        if ($adminId !== null && ($email = $this->option('admin-email'))) {
            DB::table('users')->where('id', $adminId)->where('tenant_id', $tid)->update(['email' => $email]);
        }
        if ($adminId !== null && ($password = $this->option('admin-password'))) {
            DB::table('users')->where('id', $adminId)->where('tenant_id', $tid)->update(['password' => password_hash($password, PASSWORD_BCRYPT)]);
        }

        // explicit tenant filter: never rely on RLS alone for an idempotency check
        $clients = DB::table('oauth_personal_access_clients')->where('tenant_id', $tid)->count();
        if ($clients === 0) {
            Artisan::call('passport:client', ['--personal' => true, '--name' => "Wellms Personal Access Client ({$tid})", '--no-interaction' => true]);
        }

        $this->info(sprintf(
            '%s ready: %d users, %d roles, %d permissions, %d personal clients',
            $tid,
            DB::table('users')->where('tenant_id', $tid)->count(),
            DB::table('roles')->where('tenant_id', $tid)->count(),
            DB::table('permissions')->where('tenant_id', $tid)->count(),
            DB::table('oauth_personal_access_clients')->where('tenant_id', $tid)->count(),
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
