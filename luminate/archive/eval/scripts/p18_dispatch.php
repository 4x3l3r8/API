<?php
foreach (['pooled-c.localhost' => 'job from C', 'pooled-d.localhost' => 'job from D'] as $tenant => $label) {
    \Pooled\Spike\TenantContext::$forced = $tenant;
    \Illuminate\Support\Facades\DB::statement("select set_config('app.tenant_id', ?, false)", [$tenant]);
    dispatch(new \Pooled\Spike\ProbeJob($label));
}
\Pooled\Spike\TenantContext::$forced = null;
echo "dispatched 2 jobs" . PHP_EOL;
