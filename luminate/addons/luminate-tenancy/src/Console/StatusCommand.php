<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Luminate\Tenancy\Database\AppRoleGuard;
use Luminate\Tenancy\Database\TenantPostgresConnection;

/** Health/drift report. Exit code 1 on any problem, so it can gate deployments and the isolation suite. */
final class StatusCommand extends Command
{
    protected $signature = 'luminate:tenancy:status
        {--json : machine-readable output}
        {--allow-privileged : accept running as the owner/superuser (needed to count NULL-tenant rows); never for the app}
        {--strict : production posture: no tenant-less (Swagger) exemption, no test fixtures, a forbidden-role list is set, and this process is not using a forbidden role}';
    protected $description = 'Check that tenant isolation is correctly installed (connection class, DB role, RLS coverage, drift).';

    public function handle(): int
    {
        $problems = [];
        $conn = DB::connection();

        if (!$conn instanceof TenantPostgresConnection) {
            $problems[] = 'default connection is ' . $conn::class . ', not TenantPostgresConnection (SET LOCAL not applied)';
        }

        $role = $conn->selectOne('select current_user as name, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');
        if (($role->rolsuper || $role->rolbypassrls) && !$this->option('allow-privileged')) {
            $problems[] = "database role '{$role->name}' is superuser/BYPASSRLS: row-level security would not apply";
        }

        $trigger = $conn->selectOne("select count(*) as n from pg_event_trigger where evtname = 'luminate_tenancy_ddl' and evtenabled <> 'D'");
        if ((int) $trigger->n !== 1) {
            $problems[] = 'DDL event trigger luminate_tenancy_ddl is missing or disabled (future tables would not be enrolled)';
        }

        $counts = $conn->selectOne(
            "select count(*) filter (where not public.luminate_is_exempt(c.relname)) as tenant_owned,
                    count(*) filter (where public.luminate_is_exempt(c.relname)) as exempt
             from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = 'public' and c.relkind in ('r','p')"
        );

        $drift = $conn->select('select kind, object, detail from public.luminate_tenancy_drift() order by 1, 2');
        foreach ($drift as $d) {
            // NULL-tenant rows cannot be seen (or counted) by the RLS-bound app role, so only a privileged run reports them
            $problems[] = "{$d->kind}: {$d->object}" . ($d->detail ? " ({$d->detail})" : '');
        }

        $exempt = (array) config('luminate-tenancy.exempt_paths', []);
        $fixtures = (bool) config('luminate-tenancy.fixtures');
        $forbidden = (array) config('luminate-tenancy.forbidden_db_users', []);
        if ($this->option('strict')) {
            if ($exempt !== []) {
                $problems[] = 'tenant-context exempt paths are set (' . implode(',', $exempt) . '): those routes are served with NO tenant. Production must have none.';
            }
            if ($fixtures) {
                $problems[] = 'test fixtures are enabled (LUMINATE_TENANCY_TEST_FIXTURES): never in production.';
            }
            if ($forbidden === []) {
                $problems[] = 'no forbidden database roles are configured (LUMINATE_TENANCY_FORBIDDEN_DB_USERS): nothing stops a misconfigured process using the owner.';
            }
            try {
                app(AppRoleGuard::class)->assertSafe();
            } catch (\RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $report = [
            'connection' => $conn::class,
            'exempt_paths' => $exempt,
            'fixtures' => $fixtures,
            'forbidden_db_users' => $forbidden,
            'db_role' => $role->name,
            'tenant_owned_tables' => (int) $counts->tenant_owned,
            'exempt_tables' => (int) $counts->exempt,
            'problems' => $problems,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->info("connection: {$report['connection']}");
            $this->info("db role: {$report['db_role']}");
            $this->info("tenant-owned tables: {$report['tenant_owned_tables']}  exempt: {$report['exempt_tables']}");
            foreach ($problems as $p) {
                $this->error($p);
            }
            $problems === [] ? $this->info('OK') : null;
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
