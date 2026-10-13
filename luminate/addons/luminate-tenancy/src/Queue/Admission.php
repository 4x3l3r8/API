<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Queue;

use Luminate\Tenancy\Context\ResolvedTenant;

/** The worker-side verdict on one job: run it as this tenant, or refuse it. */
final class Admission
{
    private function __construct(public readonly ?ResolvedTenant $tenant, public readonly ?TagRejected $refusal)
    {
    }

    public static function run(ResolvedTenant $tenant): self
    {
        return new self($tenant, null);
    }

    public static function refuse(TagRejected $why): self
    {
        return new self(null, $why);
    }

    public function admitted(): bool
    {
        return $this->tenant !== null;
    }
}
