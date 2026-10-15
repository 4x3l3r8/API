<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Replica;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\ResolvedTenant;
use Luminate\Tenancy\Context\TenantContext;

/**
 * Local, advisory replica of the Luminate tenant registry (contract §A.2), for work with NO signed request:
 * provisioning, queue workers, the scheduler, reconciliation. Never used on the request path (the signed
 * context token is authoritative there).
 *
 * Entries come from a ReplicaSource: the shared database table kept by push + reconcile (production), or a static
 * JSON snapshot (tests, seeding).
 */
final class TenantReplica
{
    private readonly ReplicaSource $source;

    /** @param ReplicaSource|string $source a source, or the path of a JSON snapshot (the interim file source) */
    public function __construct(ReplicaSource|string $source, private readonly PlacementResolver $placements)
    {
        $this->source = is_string($source) ? new FileReplicaSource($source) : $source;
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
        return $this->source->entries();
    }
}
