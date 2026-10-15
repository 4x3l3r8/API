<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

use RuntimeException;

/** A registry push/pull refused. Carries a stable machine code and the HTTP status the push endpoint answers with. */
final class RegistryRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('registry_disabled', 'Registry sync is not configured on this deployment.', 503);
    }

    public static function misconfigured(string $why): self
    {
        return new self('registry_misconfigured', "Registry sync misconfigured: {$why}", 503);
    }

    public static function unauthenticated(string $reason, string $message): self
    {
        return new self($reason, $message, 401);
    }

    public static function invalid(string $message): self
    {
        return new self('registry_payload_invalid', $message, 422);
    }
}
