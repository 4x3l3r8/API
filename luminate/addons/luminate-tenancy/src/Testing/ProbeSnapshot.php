<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Testing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Luminate\Tenancy\Context\CurrentTenant;

/**
 * TEST FIXTURE. Records what tenant context the current process really has, on the shared probe list the isolation suite reads.
 * Used by the scheduled probe events to prove per-tenant scheduling, including a scheduled command running in a grandchild
 * process.
 */
final class ProbeSnapshot
{
    public static function record(string $kind): void
    {
        $row = DB::selectOne("select count(*) as users_visible, coalesce(current_setting('app.tenant_id', true), '') as guc, current_user as db_user from users");
        $store = Cache::store()->getStore();

        $redis = Redis::connection();
        $redis->rpush(ProbeJob::RESULT_LIST, json_encode([
            'kind' => $kind,
            'label' => $kind . '@' . date('H:i:s'),
            'tenant' => app(CurrentTenant::class)->id(),
            'guc' => $row->guc,
            'users_visible' => (int) $row->users_visible,
            'db_user' => $row->db_user,
            'cache_prefix' => method_exists($store, 'getPrefix') ? $store->getPrefix() : null,
            'env_tenant' => getenv('LUMINATE_TENANT') ?: null,
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
        ]));
        $redis->ltrim(ProbeJob::RESULT_LIST, -2000, -1); // the dev scheduler records every minute; keep the list bounded
    }
}
