<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installs/refreshes the tenancy primitives and makes every existing tenant-owned table tenant-owned
 * (tenant_id + RLS + policy) with composite (tenant_id, ...) unique keys.
 *
 * Named 9999_... so it sorts after every Wellms migration in the same `migrate` run. Wellms tables created by FUTURE
 * upgrades are enrolled by the DDL event trigger (postgres/init/02-tenancy.sh); `luminate:tenancy:status` and the
 * isolation suite fail if anything is missed.
 *
 * Must run as the migration-owner role. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__ . '/../sql/001_functions.sql'));

        foreach ((array) config('luminate-tenancy.exempt_tables', []) as $table => $reason) {
            DB::table('luminate_tenancy_exempt')->updateOrInsert(['table_name' => $table], ['reason' => $reason]);
        }

        $enrolled = DB::selectOne('select public.luminate_enroll_all() as n')->n;
        $rewritten = DB::select('select * from public.luminate_rewrite_uniques()');

        fwrite(STDOUT, sprintf("luminate/tenancy: %d tables tenant-owned, %d unique keys made composite\n", $enrolled, count($rewritten)));

        $drift = array_filter(
            DB::select('select kind, object, detail from public.luminate_tenancy_drift()'),
            // rows written by earlier migrations before enrolment have no tenant; provisioning seeds per tenant instead
            fn ($d) => $d->kind !== 'null_tenant_rows'
        );
        if ($drift !== []) {
            throw new RuntimeException('Tenancy drift after install: ' . json_encode(array_values($drift)));
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: dropping tenant_id/RLS would silently remove tenant isolation.
    }
};
