<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Console;

use Illuminate\Console\Command;
use Luminate\Tenancy\Registry\RegistryPayload;
use Luminate\Tenancy\Registry\RegistryRejected;
use Luminate\Tenancy\Registry\RegistryRules;
use Luminate\Tenancy\Registry\RegistryStore;
use Luminate\Tenancy\Replica\FileReplicaSource;

/**
 * Seed the registry replica from a JSON snapshot (dev, first boot). It only ever inserts tenants the registry has never heard of,
 * at rev 0, so it can never overwrite or resurrect anything push/pull has decided. No snapshot file = nothing to do (production).
 */
final class ImportRegistryCommand extends Command
{
    protected $signature = 'luminate:registry:import {path? : snapshot file (default: LUMINATE_TENANCY_REPLICA)}';
    protected $description = 'Seed tenants from a JSON snapshot into the registry replica (insert-only; never overwrites).';

    public function handle(RegistryStore $store): int
    {
        $path = (string) ($this->argument('path') ?: config('luminate-tenancy.replica_path'));
        if (!is_file($path)) {
            $this->line(json_encode(['event' => 'registry.import.skipped', 'reason' => 'no snapshot file', 'path' => $path]));

            return self::SUCCESS;
        }
        $added = $kept = 0;
        try {
            foreach ((new FileReplicaSource($path))->entries() as $tid => $entry) {
                if (!RegistryPayload::validTenantId($tid)) {
                    throw RegistryRejected::invalid("snapshot tenant id '{$tid}' is not valid");
                }
                $parsed = RegistryPayload::parse($entry + ['rev' => 0]);
                if ($store->find((string) $tid) !== null) {
                    $kept++;
                    continue;
                }
                $store->apply((string) $tid, $parsed, RegistryRules::SOURCE_SEED, time());
                $added++;
            }
        } catch (\Throwable $e) {
            $this->error('registry import failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['event' => 'registry.import.ok', 'added' => $added, 'already_present' => $kept]));

        return self::SUCCESS;
    }
}
