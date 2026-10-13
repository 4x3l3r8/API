<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/** Verified claims of an X-Tenant-Context token (contract §A.1). Immutable. */
final class TenantContext
{
    public function __construct(
        public readonly string $tid,
        public readonly string $status,
        public readonly string $mode,
        public readonly string $connection,
        public readonly string $bucket,
        public readonly string $cachePrefix,
        public readonly string $jti,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
    }
}
