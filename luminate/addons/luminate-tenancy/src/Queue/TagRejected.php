<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Queue;

/** A queued job's tenant tag is missing, malformed, forged, or names a tenant that may not run. Always dead-letter. */
final class TagRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        // stable, greppable prefix: this text is what lands in failed_jobs.exception
        parent::__construct("[tenant_job_{$reason}] {$message}");
    }

    public static function missing(): self
    {
        return new self('missing', 'Job carries no tenant tag; refusing to run it against any tenant.');
    }

    public static function malformed(): self
    {
        return new self('malformed', 'Job tenant tag is malformed.');
    }

    public static function badSignature(): self
    {
        return new self('bad_signature', 'Job tenant tag signature is invalid (forged, altered or copied from another job).');
    }

    public static function misconfigured(): self
    {
        return new self('misconfigured', 'Queue tenant-tag signing key is not configured.');
    }

    public static function unknownTenant(string $tid): self
    {
        return new self('unknown_tenant', "Job is tagged for tenant '{$tid}', which this deployment does not know.");
    }

    public static function tenantInactive(string $tid, ?string $status): self
    {
        return new self('tenant_inactive', "Job is tagged for tenant '{$tid}' whose status is '{$status}'; not running it. Retry with queue:retry once the tenant is active.");
    }

    public static function replicaUnavailable(string $detail): self
    {
        return new self('replica_unavailable', 'Tenant replica unavailable: ' . $detail);
    }

    public function code(): string
    {
        return 'tenant_job_' . $this->reason;
    }
}
