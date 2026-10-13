<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Testing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Luminate\Tenancy\Context\CurrentTenant;

/**
 * TEST FIXTURE (registered only when LUMINATE_TENANCY_TEST_FIXTURES=true). Reports what tenant context a worker actually
 * gave it: the tenant it believes it is, the transaction-local DB setting it saw, how many users RLS lets it see, and the
 * cache prefix. The isolation suite reads the result list from Redis.
 */
final class ProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const RESULT_LIST = 'luminate:probe';

    public function __construct(public readonly string $label)
    {
    }

    public function handle(): void
    {
        $row = DB::selectOne("select count(*) as users_visible, coalesce(current_setting('app.tenant_id', true), '') as guc, current_user as db_user from users");
        $store = Cache::store()->getStore();

        Redis::connection()->rpush(self::RESULT_LIST, json_encode([
            'label' => $this->label,
            'tenant' => app(CurrentTenant::class)->id(),
            'guc' => $row->guc,
            'users_visible' => (int) $row->users_visible,
            'db_user' => $row->db_user,
            'cache_prefix' => method_exists($store, 'getPrefix') ? $store->getPrefix() : null,
            'pid' => getmypid(),
        ]));
    }
}
