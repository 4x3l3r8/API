<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/** Outcome of one HTTP POST: a status, or an error when no response was received. Never carries the body or secret. */
final class SendResult
{
    public function __construct(public readonly ?int $status, public readonly ?string $error = null)
    {
    }

    /** Only 2xx counts as delivered; 3xx is not followed and is a failure. */
    public function ok(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
