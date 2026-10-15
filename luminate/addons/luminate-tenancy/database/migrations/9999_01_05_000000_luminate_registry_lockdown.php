<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registry write-lockdown (contract A.2.3). The application role may only READ the replica tables; a dedicated writer role (created
 * by the database bootstrap, not here: this migration runs as the owner, which cannot create roles) holds the only write grants.
 * Without this, SQL injection under any tenant could rewrite tenant status and placement. Idempotent. Must run as the owner.
 */
return new class extends Migration {
    private const TABLES = ['luminate_tenant_registry', 'luminate_registry_meta'];

    public function up(): void
    {
        $app = (string) config('luminate-tenancy.registry.db.app_role');
        $writer = (string) config('luminate-tenancy.registry.db.username');
        if ($app === '' || $writer === '') {
            fwrite(STDOUT, "luminate/tenancy: registry lockdown SKIPPED (LUMINATE_APP_DB_USER / LUMINATE_REGISTRY_DB_USERNAME not set); `luminate:tenancy:status` will report the app role as able to write\n");

            return;
        }
        foreach ([$app, $writer] as $role) {
            if (DB::selectOne('select count(*) as n from pg_roles where rolname = ?', [$role])->n < 1) {
                throw new RuntimeException("Database role '{$role}' does not exist. The registry writer role is created with the other roles (infra/postgres/init/01-roles.sh); "
                    . 'on an existing database run infra/scripts/create-registry-role.ps1 once.');
            }
        }
        $q = static fn (string $r): string => '"' . str_replace('"', '""', $r) . '"';
        foreach (self::TABLES as $t) {
            DB::unprepared("revoke all on {$t} from public, {$q($app)}, {$q($writer)}");
            DB::unprepared("grant select on {$t} to {$q($app)}");
            DB::unprepared("grant select, insert, update on {$t} to {$q($writer)}");
        }
        fwrite(STDOUT, "luminate/tenancy: registry tables are read-only to {$app}; writes only as {$writer}\n");
    }

    public function down(): void
    {
        // Deliberately irreversible: re-opening the registry to the app role would reintroduce the vector.
    }
};
