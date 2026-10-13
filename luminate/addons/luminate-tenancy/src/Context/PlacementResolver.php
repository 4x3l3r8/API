<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/**
 * Maps the opaque placement in a verified token onto this deployment's real resources.
 * The token names things; Wellms' own config decides what they mean. Unknown => refuse.
 */
final class PlacementResolver
{
    /**
     * @param array<string,string> $connections logical name => Laravel connection name
     * @param list<string>         $allowedBuckets
     */
    public function __construct(private readonly array $connections, private readonly array $allowedBuckets)
    {
    }

    public function resolve(TenantContext $context): ResolvedTenant
    {
        $laravelConnection = $this->connections[$context->connection] ?? null;
        if ($laravelConnection === null) {
            throw ContextRejected::placementUnsupported();
        }

        // "<bucket>[/<key-prefix>]"; pooled tenants without an explicit prefix get tenants/<tid>.
        $bucket = $context->bucket;
        $prefix = '';
        if (($slash = strpos($bucket, '/')) !== false) {
            $prefix = substr($bucket, $slash + 1);
            $bucket = substr($bucket, 0, $slash);
        } elseif ($context->mode === 'pooled') {
            $prefix = 'tenants/' . $context->tid;
        }
        if (!in_array($bucket, $this->allowedBuckets, true)) {
            throw ContextRejected::placementUnsupported();
        }

        return new ResolvedTenant($context, $laravelConnection, $bucket, $prefix, $context->cachePrefix);
    }
}
