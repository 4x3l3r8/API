<?php

namespace Pooled\Spike;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/** Records what a queued job can see: tenant context, the Postgres session tenant, visible rows. */
class ProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $label)
    {
    }

    public function handle(): void
    {
        $line = sprintf(
            "%s | label=%s | ctx=%s | guc=%s | visible courses=%d users=%d\n",
            date('H:i:s'),
            $this->label,
            var_export(TenantContext::id(), true),
            var_export(DB::selectOne("select current_setting('app.tenant_id', true) as t")->t, true),
            DB::table('courses')->count(),
            DB::table('users')->count()
        );
        @file_put_contents('/tmp/spike_jobs.log', $line, FILE_APPEND);
    }
}
