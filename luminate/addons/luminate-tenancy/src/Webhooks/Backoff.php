<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/**
 * Retry schedule for contract v4 §F: "+0, +1m, +5m, +30m, +2h, +6h, cap ~24h".
 *
 * Reading of the contract (it says "e.g."): the first attempt is immediate; after failure N the next attempt waits
 * delays[N-1] seconds (the last delay repeats); once the NEXT attempt would fall beyond max_age since the event was
 * recorded, the delivery is dead-lettered and the reconciliation pull covers the gap. With the defaults that is 8 attempts
 * over about 20.6 hours.
 */
final class Backoff
{
    /** @param list<int> $delays seconds to wait after the 1st, 2nd, ... failure; the last value repeats */
    public function __construct(private readonly array $delays, private readonly int $maxAge)
    {
        if ($delays === [] || min($delays) < 0) {
            throw new \InvalidArgumentException('Backoff needs at least one non-negative delay.');
        }
        if ($maxAge < 1) {
            throw new \InvalidArgumentException('Backoff max age must be positive.');
        }
    }

    /** Seconds to wait after attempt number $attemptsMade (1-based) has failed. */
    public function delayAfter(int $attemptsMade): int
    {
        $i = max(0, min($attemptsMade - 1, count($this->delays) - 1));

        return $this->delays[$i];
    }

    /** True when retrying would land beyond the cap: dead-letter instead. */
    public function exhausted(int $attemptsMade, int $ageSeconds): bool
    {
        return $ageSeconds + $this->delayAfter($attemptsMade) > $this->maxAge;
    }
}
