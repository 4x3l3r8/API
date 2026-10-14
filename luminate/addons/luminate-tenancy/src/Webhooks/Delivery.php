<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/** One outbox row as claimed for an attempt. `attempts` already includes the attempt being made. */
final class Delivery
{
    public function __construct(
        public readonly int $id,
        public readonly string $deliveryId,
        public readonly string $event,
        public readonly string $body,
        public readonly int $attempts,
        public readonly int $timestamp,
        public readonly int $createdAt,
    ) {
    }
}
