<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/** A verified tenant context plus what its placement means on this deployment. */
final class ResolvedTenant
{
    public function __construct(
        public readonly TenantContext $context,
        public readonly string $laravelConnection,
        public readonly string $bucket,
        public readonly string $s3KeyPrefix,
        public readonly string $cachePrefix,
    ) {
    }

    public function id(): string
    {
        return $this->context->tid;
    }
}
