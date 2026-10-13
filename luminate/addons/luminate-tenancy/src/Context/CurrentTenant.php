<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Context;

/**
 * The tenant the current request / job runs as. Bound as a singleton.
 * Empty means "no tenant": everything downstream must fail closed, never default.
 */
final class CurrentTenant
{
    private ?ResolvedTenant $tenant = null;

    public function set(ResolvedTenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?ResolvedTenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->id();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
