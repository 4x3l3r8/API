<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

final class DeliveryOutcome
{
    public const SKIPPED = 'skipped';     // not claimable: already delivered/dead, not due, or another worker holds it
    public const DELIVERED = 'delivered';
    public const RETRY = 'retry';         // failed; scheduled again after $retryIn seconds
    public const DEAD = 'dead';           // retries exhausted

    public function __construct(
        public readonly string $kind,
        public readonly ?int $retryIn = null,
        public readonly ?int $httpStatus = null,
    ) {
    }
}
