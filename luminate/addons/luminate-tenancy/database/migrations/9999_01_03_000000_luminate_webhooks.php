<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook outbox (contract v4 §F). A normal tenant-owned table: the DDL event trigger adds tenant_id (defaulted from the
 * transaction-local setting), the index and the RLS policy the moment it is created, exactly as for any Wellms table.
 * Times are Unix seconds (bigint) so there is no timezone ambiguity between PHP, SQL and the retry schedule.
 * Must run as the migration-owner role. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('luminate_webhook_deliveries')) {
            Schema::create('luminate_webhook_deliveries', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->uuid('delivery_id');
                $t->string('event', 64);
                $t->text('body');                                   // the exact bytes that are signed and sent, on every retry
                $t->string('status', 12)->default('pending');        // pending | delivered | dead
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedBigInteger('next_attempt_at');           // also the worker lease while an attempt is in flight
                $t->unsignedBigInteger('first_sent_at')->nullable(); // X-Wellms-Timestamp: stable across retries
                $t->unsignedBigInteger('delivered_at')->nullable();
                $t->unsignedSmallInteger('last_status')->nullable();
                $t->string('last_error', 300)->nullable();           // never the body, the secret or the receiver URL
                $t->unsignedBigInteger('created_at');
                $t->unsignedBigInteger('updated_at');
            });
        }

        // tenant_id exists now (added by the event trigger): the natural key is composite, like every other unique key
        DB::statement('create unique index if not exists luminate_webhook_deliveries_tid_delivery on luminate_webhook_deliveries (tenant_id, delivery_id)');
        DB::statement('create index if not exists luminate_webhook_deliveries_due on luminate_webhook_deliveries (status, next_attempt_at)');

        $drift = array_filter(
            DB::select('select kind, object, detail from public.luminate_tenancy_drift()'),
            fn ($d) => $d->kind !== 'null_tenant_rows'
        );
        if ($drift !== []) {
            throw new RuntimeException('Tenancy drift after creating the webhook outbox: ' . json_encode(array_values($drift)));
        }
    }

    public function down(): void
    {
        // Deliberately irreversible, like the other tenancy migrations.
    }
};
