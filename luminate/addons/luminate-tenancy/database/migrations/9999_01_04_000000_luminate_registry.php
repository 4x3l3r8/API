<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local replica of the Luminate tenant registry (contract A.2): which tenants exist, their status and placement.
 *
 * These tables are GLOBAL on purpose. They are what says which tenants exist, they are read by workers and the scheduler with no
 * tenant active, and a tenant_id column would be circular. So they are registered as tenancy-exempt BEFORE they are created, which
 * is what keeps the DDL event trigger from enrolling them. Must run as the migration-owner role. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['luminate_tenant_registry' => 'local replica of the Luminate tenant registry (contract A.2); global by design',
                  'luminate_registry_meta' => 'registry reconcile bookkeeping'] as $table => $reason) {
            DB::table('luminate_tenancy_exempt')->updateOrInsert(['table_name' => $table], ['reason' => $reason]);
        }

        if (!Schema::hasTable('luminate_tenant_registry')) {
            Schema::create('luminate_tenant_registry', function (Blueprint $t) {
                $t->string('tenant_id', 64)->primary();
                $t->string('status', 16);                       // provisioning | active | suspended | removed (tombstone)
                $t->unsignedBigInteger('rev');                  // Luminate's monotonic per-tenant revision
                $t->string('mode', 16);
                $t->string('connection', 64);
                $t->string('bucket', 255);
                $t->string('cache_prefix', 64);
                $t->string('source', 8);                        // push | pull | seed | absent
                $t->unsignedBigInteger('provisioned_at')->nullable();
                $t->unsignedBigInteger('created_at');
                $t->unsignedBigInteger('updated_at');
            });
        }
        if (!Schema::hasTable('luminate_registry_meta')) {
            Schema::create('luminate_registry_meta', function (Blueprint $t) {
                $t->string('k', 64)->primary();
                $t->text('v');
            });
        }

        $drift = DB::select('select kind, object, detail from public.luminate_tenancy_drift()');
        $drift = array_filter($drift, fn ($d) => $d->kind !== 'null_tenant_rows');
        if ($drift !== []) {
            throw new RuntimeException('Tenancy drift after creating the registry tables: ' . json_encode(array_values($drift)));
        }
    }

    public function down(): void
    {
        // Deliberately irreversible, like the other tenancy migrations.
    }
};
