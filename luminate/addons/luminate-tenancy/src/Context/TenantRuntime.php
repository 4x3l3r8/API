<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

use Illuminate\Support\Facades\Cache;

/**
 * The single place that switches the process to a tenant (HTTP request, provisioning, queue job, scheduler tick).
 * Everything tenant-scoped that is NOT the database hangs off this: today the cache prefix; S3 key prefix and queue
 * tagging build on it next.
 *
 * Fail-safe default: with no tenant the cache prefix is UNTENANTED, a namespace no tenant ever uses, so boot-time reads
 * (e.g. `escolalms/settings` loading config in provider boot()) can never read or poison a tenant's keys.
 */
final class TenantRuntime
{
    public const UNTENANTED = 'untenanted';

    public function __construct(private readonly CurrentTenant $current)
    {
    }

    /**
     * @param bool $longLivedProcess true for CLI loops / queue workers that switch tenants inside one process: also drops
     *                               in-memory state that would otherwise carry the previous tenant's data across.
     */
    public function activate(ResolvedTenant $tenant, bool $longLivedProcess = false): void
    {
        $this->current->set($tenant);
        $this->useCachePrefix($tenant->cachePrefix);
        if ($longLivedProcess) {
            $this->forgetInProcessState();
        }
    }

    public function deactivate(bool $longLivedProcess = false): void
    {
        $this->current->clear();
        $this->useCachePrefix(self::UNTENANTED);
        if ($longLivedProcess) {
            $this->forgetInProcessState();
        }
    }

    private function useCachePrefix(string $prefix): void
    {
        // mutate the one shared store object so repositories that already hold a reference (e.g. Spatie's) follow it
        $store = Cache::store()->getStore();
        if (method_exists($store, 'setPrefix')) {
            $store->setPrefix($prefix);
        } else {
            throw new \RuntimeException('Cache store ' . $store::class . ' cannot be given a per-tenant prefix; use the redis store.');
        }
    }

    private function forgetInProcessState(): void
    {
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class) && app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->clearPermissionsCollection();
        }
    }
}
