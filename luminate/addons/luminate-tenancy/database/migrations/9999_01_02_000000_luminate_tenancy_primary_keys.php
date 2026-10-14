<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wellms' `config` table (all tenant settings live in one row, id = 1) is the only table whose primary key the application
 * assigns itself. With a global PK the second tenant to save settings would fail with a duplicate key. This makes such PKs
 * composite: (tenant_id, id).
 *
 * Pattern for evolving the tenancy SQL: the install migration has already run on existing databases, so every change to
 * database/sql/001_functions.sql ships with a NEW migration like this one that re-applies the file (CREATE OR REPLACE) and
 * then runs the new step. Must run as the migration-owner role. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__ . '/../sql/001_functions.sql'));

        $rewritten = DB::select('select * from public.luminate_rewrite_primary_keys()');
        fwrite(STDOUT, sprintf("luminate/tenancy: %d application-assigned primary key(s) made composite\n", count($rewritten)));

        $drift = array_filter(
            DB::select('select kind, object, detail from public.luminate_tenancy_drift()'),
            fn ($d) => $d->kind !== 'null_tenant_rows'
        );
        if ($drift !== []) {
            throw new RuntimeException('Tenancy drift after primary-key rewrite: ' . json_encode(array_values($drift)));
        }
    }

    public function down(): void
    {
        // Deliberately irreversible, like the install migration.
    }
};
