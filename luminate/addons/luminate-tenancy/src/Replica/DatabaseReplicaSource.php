<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Replica;

use Luminate\Tenancy\Registry\RegistryStore;

/**
 * The replica as kept by push + reconcile, read from the shared registry table. Tombstoned ("removed") tenants are not entries:
 * to every consumer they are unknown, which fails closed.
 *
 * A short in-process cache keeps a busy worker from querying per job. That is the "bounded staleness" the contract allows for
 * out-of-request work: a suspension reaches a running worker within `$ttl` seconds. 0 = read every time.
 */
final class DatabaseReplicaSource implements ReplicaSource
{
    /** @var array<string,array{status:string,placement:array<string,string>}>|null */
    private ?array $cached = null;
    private int $cachedAt = 0;

    public function __construct(private readonly RegistryStore $store, private readonly int $ttl = 2, private readonly ?\Closure $clock = null)
    {
    }

    public function entries(): array
    {
        $now = $this->clock ? (int) ($this->clock)() : time();
        if ($this->cached !== null && $this->ttl > 0 && $now - $this->cachedAt < $this->ttl) {
            return $this->cached;
        }
        $entries = [];
        foreach ($this->store->all() as $tid => $row) {
            if ($row['status'] !== 'removed') {
                $entries[$tid] = ['status' => $row['status'], 'placement' => $row['placement']];
            }
        }
        $this->cachedAt = $now;

        return $this->cached = $entries;
    }
}
