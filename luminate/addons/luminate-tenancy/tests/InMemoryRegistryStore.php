<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Registry\RegistryRules;
use Luminate\Tenancy\Registry\RegistryStore;

/** Test double: the same RegistryRules the database store uses, minus the database. */
final class InMemoryRegistryStore implements RegistryStore
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];
    /** @var list<array{ok:bool,error:?string,as_of:?int}> */
    public array $pulls = [];

    public function find(string $tid): ?array
    {
        return $this->rows[$tid] ?? null;
    }

    public function all(): array
    {
        return $this->rows;
    }

    public function apply(string $tid, array $incoming, string $source, int $now): string
    {
        $outcome = RegistryRules::decide($this->rows[$tid] ?? null, $incoming);
        if ($outcome === RegistryRules::APPLIED) {
            $this->rows[$tid] = [
                'tenant_id' => $tid, 'status' => $incoming['status'], 'rev' => $incoming['rev'], 'placement' => $incoming['placement'],
                'source' => $source, 'provisioned_at' => $this->rows[$tid]['provisioned_at'] ?? null, 'updated_at' => $now,
            ];
        }

        return $outcome;
    }

    public function tombstoneAbsent(array $presentTids, int $touchedBefore, int $now): array
    {
        $gone = [];
        foreach ($this->rows as $tid => $row) {
            if ($row['status'] !== 'removed' && !in_array($tid, $presentTids, true) && $row['updated_at'] < $touchedBefore) {
                $this->rows[$tid]['status'] = 'removed';
                $this->rows[$tid]['source'] = RegistryRules::SOURCE_ABSENT;
                $this->rows[$tid]['updated_at'] = $now;
                $gone[] = $tid;
            }
        }

        return $gone;
    }

    public function markProvisioned(string $tid, int $now): void
    {
        $this->rows[$tid]['provisioned_at'] ??= $now;
    }

    public function pendingProvision(): array
    {
        return array_keys(array_filter($this->rows, fn ($r) => in_array($r['status'], ['provisioning', 'active'], true) && $r['provisioned_at'] === null));
    }

    public function recordPull(bool $ok, ?string $error, ?int $asOf, int $now): void
    {
        $this->pulls[] = ['ok' => $ok, 'error' => $error, 'as_of' => $asOf];
    }

    public function pullState(): array
    {
        return ['attempt_at' => null, 'ok_at' => null, 'error' => null, 'as_of' => null];
    }
}
