<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

/**
 * Persistence of the local registry replica (contract §A.2). One row per tenant id, global (NOT tenant-owned: it is the thing
 * that says which tenants exist), shared by every api/worker/scheduler process through the database.
 *
 * @phpstan-type Row array{tenant_id:string,status:string,rev:int,placement:array{mode:string,connection:string,bucket:string,cache_prefix:string},source:string,provisioned_at:?int,updated_at:int}
 */
interface RegistryStore
{
    /** @return Row|null */
    public function find(string $tid): ?array;

    /** @return array<string,Row> every row including tombstones, keyed by tenant id */
    public function all(): array;

    /**
     * Apply one entry under RegistryRules (atomic per tenant). Returns the RegistryRules outcome.
     *
     * @param array{status:string,rev:int,placement:array<string,string>} $incoming
     */
    public function apply(string $tid, array $incoming, string $source, int $now): string;

    /**
     * Tombstone (status "removed") every live tenant that is not in $presentTids AND was not touched at or after $touchedBefore:
     * a tenant pushed after the authoritative snapshot was cut must not be removed by that older snapshot.
     *
     * @param list<string> $presentTids
     *
     * @return list<string> the tenants tombstoned
     */
    public function tombstoneAbsent(array $presentTids, int $touchedBefore, int $now): array;

    public function markProvisioned(string $tid, int $now): void;

    /** @return list<string> tenants in provisioning|active that have never been provisioned */
    public function pendingProvision(): array;

    public function recordPull(bool $ok, ?string $error, ?int $asOf, int $now): void;

    /** @return array{attempt_at:?int,ok_at:?int,error:?string,as_of:?int} */
    public function pullState(): array;
}
