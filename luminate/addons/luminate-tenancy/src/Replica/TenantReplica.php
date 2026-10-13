<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Replica;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\ResolvedTenant;
use Luminate\Tenancy\Context\TenantContext;
use RuntimeException;

/**
 * Local, advisory replica of the Luminate tenant registry (contract §A.2), for work with NO signed request:
 * provisioning, queue workers, the scheduler, reconciliation. Never used on the request path (the signed
 * context token is authoritative there).
 *
 * Interim implementation: a static JSON snapshot mirroring the registry. FOLLOW-UP (not built): push-from-Luminate
 * on change plus a periodic reconcile pull, which would write this same file/shape.
 *
 *   { "version": 1, "tenants": { "<tid>": { "status": "active",
 *       "placement": { "mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": "<tid>" } } } }
 */
final class TenantReplica
{
    /** @var array<string,array{status:string,placement:array<string,string>}>|null */
    private ?array $tenants = null;

    public function __construct(private readonly string $path, private readonly PlacementResolver $placements)
    {
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->load());
    }

    public function has(string $tid): bool
    {
        return isset($this->load()[$tid]);
    }

    public function status(string $tid): ?string
    {
        return $this->load()[$tid]['status'] ?? null;
    }

    /**
     * Resolve a tenant for out-of-request work. Unknown tenant or a status outside $allowedStatuses => refused
     * (fail closed). Provisioning passes ['provisioning', 'active']; workers pass ['active'].
     *
     * @param list<string> $allowedStatuses
     */
    public function resolve(string $tid, array $allowedStatuses = ['active']): ResolvedTenant
    {
        $entry = $this->load()[$tid] ?? null;
        if ($entry === null) {
            throw ContextRejected::placementUnsupported();
        }
        if (!in_array($entry['status'], $allowedStatuses, true)) {
            throw ContextRejected::tenantInactive();
        }
        $p = $entry['placement'];
        $now = time();

        return $this->placements->resolve(new TenantContext(
            $tid, $entry['status'], $p['mode'], $p['connection'], $p['bucket'], $p['cache_prefix'], 'replica', $now, $now
        ));
    }

    /** @return array<string,array{status:string,placement:array<string,string>}> */
    private function load(): array
    {
        if ($this->tenants !== null) {
            return $this->tenants;
        }
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException("Tenant replica snapshot not found at {$this->path}");
        }
        $data = json_decode((string) file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
        $tenants = $data['tenants'] ?? null;
        if (!is_array($tenants)) {
            throw new RuntimeException('Tenant replica snapshot has no "tenants" object');
        }
        foreach ($tenants as $tid => $entry) {
            $p = $entry['placement'] ?? null;
            if (!is_string($tid) || !is_string($entry['status'] ?? null) || !is_array($p)
                || !is_string($p['mode'] ?? null) || !is_string($p['connection'] ?? null)
                || !is_string($p['bucket'] ?? null) || !is_string($p['cache_prefix'] ?? null)) {
                throw new RuntimeException("Tenant replica entry for '{$tid}' is incomplete");
            }
        }

        return $this->tenants = $tenants;
    }
}
