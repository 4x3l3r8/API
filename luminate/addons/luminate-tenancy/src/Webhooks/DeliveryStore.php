<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/**
 * The webhook outbox. A tenant-owned table: every call runs in the active tenant's context, so row-level security keeps
 * one tenant's deliveries invisible to another. All times are Unix seconds.
 */
interface DeliveryStore
{
    /** Record a new pending delivery (due immediately). Returns its row id. */
    public function insert(string $deliveryId, string $event, string $body, int $now): int;

    /**
     * Atomically take the row for ONE attempt: only if it is pending and due. Increments `attempts`, sets the lease
     * (`next_attempt_at = now + lease`, so a crashed worker's row becomes due again) and fixes the first-send timestamp.
     * Returns null when the row is not claimable (already delivered/dead, not due, or another worker holds it).
     */
    public function claim(int $id, int $leaseSeconds, int $now): ?Delivery;

    public function markDelivered(int $id, int $httpStatus, int $now): void;

    public function markFailed(int $id, ?int $httpStatus, string $error, int $nextAttemptAt, int $now): void;

    /** Retries exhausted: dead-letter the delivery (the reconciliation pull covers the gap). */
    public function markDead(int $id, ?int $httpStatus, string $error, int $now): void;

    /** @return list<int> ids of pending deliveries that are due */
    public function dueIds(int $now, int $limit): array;

    /** Delete delivered rows older than $deliveredBefore and dead rows older than $deadBefore. Returns rows deleted. */
    public function prune(int $deliveredBefore, int $deadBefore): int;
}
