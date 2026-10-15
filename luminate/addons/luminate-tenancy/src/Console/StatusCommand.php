<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Luminate\Tenancy\Database\AppRoleGuard;
use Luminate\Tenancy\Database\TenantPostgresConnection;
use Luminate\Tenancy\Registry\RegistryStore;

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

        $registry = $this->registryReport($problems);
        if (!$this->option('allow-privileged')) {
            $this->checkRegistryLockdown($conn, $problems);
        }

        $report = [
            'connection' => $conn::class,
            'exempt_paths' => $exempt,
            'fixtures' => $fixtures,
            'forbidden_db_users' => $forbidden,
            'db_role' => $role->name,
            'registry' => $registry,
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
            $this->info('registry replica: ' . json_encode($registry));
            foreach ($problems as $p) {
                $this->error($p);
            }
            $problems === [] ? $this->info('OK') : null;
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The registry replica (contract A.2). Staleness is reported, never enforced: bounded staleness is allowed, and refusing work
     * because Luminate is briefly unreachable would be a worse outage than the one it guards against.
     *
     * @param list<string> $problems
     *
     * @return array<string,mixed>
     */
    private function registryReport(array &$problems): array
    {
        $cfg = (array) config('luminate-tenancy');
        $driver = (string) $cfg['replica_driver'];
        $set = static fn ($k) => is_string($k) && $k !== '';
        $keys = array_values(array_filter([$cfg['registry']['keys']['current'] ?? null, $cfg['registry']['keys']['previous'] ?? null], $set));
        $ctx = array_values(array_filter([$cfg['keys']['current'] ?? null, $cfg['keys']['previous'] ?? null], $set));
        $report = ['driver' => $driver, 'keys_configured' => $keys !== [], 'pull_configured' => (string) $cfg['registry']['pull_url'] !== ''];

        if (array_intersect($keys, $ctx) !== []) {
            $problems[] = 'the registry key equals a tenant-context key: registry sync is disabled (use a distinct key)';
        }
        if ($this->option('strict')) {
            if ($driver !== 'database') {
                $problems[] = "replica driver is '{$driver}': production must use 'database' (push + reconcile), not a static file";
            }
            if ($keys === []) {
                $problems[] = 'no registry key is configured (LUMINATE_REGISTRY_KEY): Luminate cannot push tenant changes';
            }
            if (!$report['pull_configured']) {
                $problems[] = 'no registry pull url is configured (LUMINATE_REGISTRY_PULL_URL): there is no reconciliation safety net behind push';
            }
            if ($cfg['registry']['allow_http']) {
                $problems[] = 'registry pull over plain http is allowed (LUMINATE_REGISTRY_ALLOW_HTTP): dev only';
            }
        }
        if ($driver !== 'database') {
            return $report;
        }

        try {
            $store = app(RegistryStore::class);
            $rows = $store->all();
            $state = $store->pullState();
        } catch (\Throwable $e) {
            $problems[] = 'registry replica unreadable: ' . $e->getMessage();

            return $report;
        }
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
        }
        $report += ['tenants' => $byStatus, 'pending_provision' => $store->pendingProvision(), 'last_pull' => $state];

        if ($report['pull_configured']) {
            $age = $state['ok_at'] === null ? null : time() - $state['ok_at'];
            if ($age === null || $age > (int) $cfg['registry']['stale_after']) {
                $problems[] = 'registry replica is stale: last successful reconcile ' . ($age === null ? 'never' : "{$age}s ago") . ($state['error'] ? " (last error: {$state['error']})" : '');
            }
        }

        return $report;
    }

    /**
     * Contract A.2.3: the application role must not be able to write the registry (SQL injection under any tenant would otherwise
     * rewrite tenant status and placement), and a writer role must be configured and must not be usable for tenant work.
     *
     * @param list<string> $problems
     */
    private function checkRegistryLockdown(object $conn, array &$problems): void
    {
        if (config('luminate-tenancy.replica_driver') !== 'database') {
            return;
        }
        foreach (['luminate_tenant_registry', 'luminate_registry_meta'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
                $has = $conn->selectOne('select has_table_privilege(current_user, ?, ?) as ok', [$table, $priv]);
                if ($has->ok) {
                    $problems[] = "the application role can {$priv} {$table}: writes must go through the registry writer role (contract A.2.3)";
                }
            }
        }
        $writer = (string) config('luminate-tenancy.registry.db.username');
        if ($writer === '') {
            $problems[] = 'no registry writer role is configured (LUMINATE_REGISTRY_DB_USERNAME): the registry cannot be written';
        } elseif (!in_array($writer, (array) config('luminate-tenancy.forbidden_db_users', []), true) && $this->option('strict')) {
            $problems[] = "the registry writer role '{$writer}' is not in the forbidden-roles list: a misconfigured process could serve tenant work as it";
        }
    }
}
