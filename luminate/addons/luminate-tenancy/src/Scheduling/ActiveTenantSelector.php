<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Scheduling;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * Which tenants does the scheduler run for this tick? Driven by the local replica (contract §A.2), never by a static list
 * like Wellms' MULTI_DOMAINS. Only `active` tenants whose placement this deployment can serve run; everything else is
 * skipped WITH A REASON so an operator can see why. There is no "tenant-less" run.
 *
 * Throws when the replica itself cannot be read: the caller must then skip the whole tick (fail closed), not guess.
 */
final class ActiveTenantSelector
{
    /** @return array{run: list<string>, skipped: array<string,string>} */
    public function select(TenantReplica $replica): array
    {
        $run = [];
        $skipped = [];

        foreach ($replica->ids() as $tid) {
            $status = $replica->status($tid);
            if ($status !== 'active') {
                $skipped[$tid] = "status {$status}";
                continue;
            }
            try {
                $replica->resolve($tid, ['active']);
            } catch (ContextRejected $e) {
                $skipped[$tid] = $e->reason;
                continue;
            }
            $run[] = $tid;
        }

        return ['run' => $run, 'skipped' => $skipped];
    }
}
