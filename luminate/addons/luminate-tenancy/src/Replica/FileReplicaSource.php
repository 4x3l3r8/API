<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Replica;

use RuntimeException;

/**
 * A static JSON snapshot mirroring the registry. The interim source, kept for tests and for seeding a fresh database
 * (`luminate:registry:import`). Not for production: nothing keeps it in step with Luminate.
 *
 *   { "version": 1, "tenants": { "<tid>": { "status": "active",
 *       "placement": { "mode": "pooled", "connection": "shard-1", "bucket": "wellms", "cache_prefix": "<tid>" } } } }
 */
final class FileReplicaSource implements ReplicaSource
{
    /** @var array<string,array{status:string,placement:array<string,string>}>|null */
    private ?array $tenants = null;
    private int $loadedMtime = 0;

    public function __construct(private readonly string $path)
    {
    }

    public function entries(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException("Tenant replica snapshot not found at {$this->path}");
        }
        // long-lived workers/schedulers: pick up a rewritten snapshot (status changes, new tenants) without a restart
        clearstatcache(true, $this->path);
        $mtime = (int) filemtime($this->path);
        if ($this->tenants !== null && $mtime === $this->loadedMtime) {
            return $this->tenants;
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

        $this->loadedMtime = $mtime;

        return $this->tenants = $tenants;
    }
}
