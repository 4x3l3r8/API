<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The single place that switches the process to a tenant (HTTP request, provisioning, queue job, scheduler tick).
 * Everything tenant-scoped that is NOT the database hangs off this:
 *
 *  - the cache prefix (fail-safe default UNTENANTED: a namespace no tenant uses, so boot-time reads can never read or
 *    poison a tenant's keys);
 *  - file storage: S3 bucket + key prefix and local scratch directories, per tenant (see useStorage());
 *  - DB-backed settings (`escolalms/settings`): its provider loads them in boot(), BEFORE any tenant is known, so they must be
 *    re-loaded for the tenant once it is active;
 *  - in long-lived processes (queue workers, CLI loops) the pre-tenant configuration baseline is restored between tenants,
 *    so one tenant's settings can never leak into the next job.
 *
 * Octane/Swoole-style runtimes that share a process between requests are unsupported.
 */
final class TenantRuntime
{
    public const UNTENANTED = 'untenanted';
    private const SETTINGS_CONTRACT = 'EscolaLms\\Settings\\Services\\Contracts\\AdministrableConfigServiceContract';

    /** @var array<string,mixed>|null configuration as it was after boot, before any tenant was applied */
    private ?array $baselineConfig = null;

    public function __construct(private readonly CurrentTenant $current)
    {
    }

    /**
     * @param bool $longLivedProcess true for CLI loops / queue workers that switch tenants inside one process: also drops
     *                               in-memory state that would otherwise carry the previous tenant's data across.
     */
    public function activate(ResolvedTenant $tenant, bool $longLivedProcess = false): void
    {
        $this->captureBaseline();
        if ($longLivedProcess) {
            $this->restoreBaseline();
        }

        $this->current->set($tenant);
        $this->useCachePrefix($tenant->cachePrefix);
        $this->useStorage($tenant);
        if ($longLivedProcess) {
            $this->forgetInProcessState();
        }
        $this->loadTenantSettings();
    }

    public function deactivate(bool $longLivedProcess = false): void
    {
        $this->captureBaseline();

        $this->current->clear();
        $this->useCachePrefix(self::UNTENANTED);
        $this->useStorage(null);
        if ($longLivedProcess) {
            $this->restoreBaseline();
            $this->useStorage(null); // the baseline restore above put the original disk roots back
            $this->forgetInProcessState();
        }
    }

    /**
     * Point every file disk at the tenant's own area (contract §A.1: S3 key prefix derived from placement).
     *  - s3 disks:    bucket from the placement (already allow-listed) and `root` = the tenant's key prefix;
     *  - local disks: `<original root>/tenants/<tid>` (Wellms uses them as import/export/SCORM scratch space, including
     *                 deleteDirectory(), so a shared directory would let one tenant clobber another's files);
     *  - no tenant:   an `untenanted` area, never the bucket root or the shared directory.
     * Always derived from the pre-tenant baseline, never from the previous tenant's values, and the cached disk instances are
     * dropped so the next use rebuilds them.
     */
    private function useStorage(?ResolvedTenant $tenant): void
    {
        $original = (array) ($this->baselineConfig['filesystems']['disks'] ?? app('config')->get('filesystems.disks', []));
        $config = app('config');

        foreach ($original as $name => $disk) {
            $driver = $disk['driver'] ?? null;
            if ($driver === 's3') {
                $config->set("filesystems.disks.{$name}.root", $tenant ? $tenant->s3KeyPrefix : self::UNTENANTED);
                if ($tenant) {
                    $config->set("filesystems.disks.{$name}.bucket", $tenant->bucket);
                }
            } elseif ($driver === 'local') {
                $root = rtrim((string) ($disk['root'] ?? ''), '/\\');
                $config->set("filesystems.disks.{$name}.root", $root . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . ($tenant ? $tenant->id() : self::UNTENANTED));
            } else {
                continue;
            }
            Storage::forgetDisk($name);
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

    private function captureBaseline(): void
    {
        // first call in a process happens before any tenant has been applied (the middleware and the worker hooks call
        // deactivate() first), so this is the untenanted post-boot configuration
        $this->baselineConfig ??= app('config')->all();
    }

    private function restoreBaseline(): void
    {
        if ($this->baselineConfig !== null) {
            app('config')->set($this->baselineConfig);
        }
    }

    /**
     * Load this tenant's DB-backed settings (per-tenant cache first, then the tenant's own `config` row). Deliberately NOT
     * wrapped in a try/catch: if a tenant's settings cannot be read, fail the request/job rather than silently serve defaults
     * that may be more permissive than what the tenant configured (registration, account approval, token lifetime, ...).
     */
    private function loadTenantSettings(): void
    {
        if (!interface_exists(self::SETTINGS_CONTRACT) || !app()->bound(self::SETTINGS_CONTRACT)) {
            return;
        }
        $settings = app(self::SETTINGS_CONTRACT);
        if (!$settings->loadConfigFromCache()) {
            $settings->loadConfigFromDatabase();
        }
    }

    private function forgetInProcessState(): void
    {
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class) && app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->clearPermissionsCollection();
        }
    }
}
