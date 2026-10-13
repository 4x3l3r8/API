<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/** A tenant-context token (or the placement it names) was refused. Always fail closed. */
final class ContextRejected extends \RuntimeException
{
    private function __construct(public readonly string $reason, public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('missing', 401, 'Tenant context is required.');
    }

    public static function malformed(): self
    {
        return new self('malformed', 401, 'Tenant context is malformed.');
    }

    public static function badAlgorithm(): self
    {
        return new self('bad_algorithm', 401, 'Tenant context uses an unsupported algorithm.');
    }

    public static function badSignature(): self
    {
        return new self('bad_signature', 401, 'Tenant context signature is invalid.');
    }

    public static function invalidClaims(string $detail): self
    {
        return new self('invalid_claims', 401, 'Tenant context claims are invalid: ' . $detail);
    }

    public static function expired(): self
    {
        return new self('expired', 401, 'Tenant context has expired.');
    }

    public static function notYetValid(): self
    {
        return new self('not_yet_valid', 401, 'Tenant context is not yet valid.');
    }

    public static function tenantInactive(): self
    {
        return new self('tenant_inactive', 403, 'Tenant is not active.');
    }

    public static function placementUnsupported(): self
    {
        return new self('placement_unsupported', 403, 'Tenant placement is not supported by this deployment.');
    }

    public static function misconfigured(): self
    {
        return new self('misconfigured', 503, 'Tenant context verification is not configured.');
    }

    /** Stable machine-readable code for responses and logs. */
    public function code(): string
    {
        return 'tenant_context_' . $this->reason;
    }
}
