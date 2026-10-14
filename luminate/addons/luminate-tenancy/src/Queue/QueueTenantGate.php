<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Queue;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * Decides, from the job payload alone, whether a worker may run a job and as which tenant (contract §A.2: workers read
 * status/placement from the local replica, since there is no signed request). Pure: no DB, no cache, no container.
 *
 * Fail closed: anything other than "validly tagged AND the tagged tenant is known AND active AND its placement is supported
 * here" is a refusal. There is no default tenant and no fallback.
 */
final class QueueTenantGate
{
    /** @param list<string> $deniedJobs job classes that must never run on this deployment, whatever tenant or tag they carry */
    public function __construct(
        private readonly QueueTagger $tagger,
        private readonly TenantReplica $replica,
        private readonly array $deniedJobs = [],
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function admit(array $payload): Admission
    {
        foreach ([$payload['displayName'] ?? null, $payload['data']['commandName'] ?? null] as $name) {
            if (is_string($name) && in_array($name, $this->deniedJobs, true)) {
                return Admission::refuse(TagRejected::jobDenied($name));
            }
        }

        try {
            $tid = $this->tagger->verify($payload);

            try {
                if (!$this->replica->has($tid)) {
                    throw TagRejected::unknownTenant($tid);
                }
                $status = $this->replica->status($tid);
                if ($status !== 'active') {
                    throw TagRejected::tenantInactive($tid, $status);
                }

                return Admission::run($this->replica->resolve($tid, ['active']));
            } catch (ContextRejected $e) {
                // known tenant whose placement this deployment cannot serve
                throw TagRejected::unknownTenant($tid);
            } catch (TagRejected $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw TagRejected::replicaUnavailable($e->getMessage());
            }
        } catch (TagRejected $e) {
            return Admission::refuse($e);
        }
    }
}
