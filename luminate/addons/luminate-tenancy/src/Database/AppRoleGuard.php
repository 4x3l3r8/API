<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Database;

use RuntimeException;

/**
 * Request, queue-job and provisioning work must run as the RLS-bound APP role. The migration owner (which owns the tables, so
 * row-level security does not apply to it) and the bootstrap superuser (which bypasses RLS) must never serve tenant work, and a
 * mis-set DB_USERNAME in a deployment would otherwise silently turn isolation off.
 *
 * This is a plain comparison of the configured connection user against a forbidden list (no query, so it is cheap enough to run
 * on every request). It is a fast first line; `luminate:tenancy:status` additionally asks Postgres what the role really is.
 */
final class AppRoleGuard
{
    /** @param list<string> $forbiddenUsers */
    public function __construct(private readonly array $forbiddenUsers, private readonly string $configuredUser)
    {
    }

    /** @throws RuntimeException when the process is configured to connect as a role that bypasses row-level security */
    public function assertSafe(): void
    {
        $user = strtolower(trim($this->configuredUser));
        foreach ($this->forbiddenUsers as $forbidden) {
            if ($user !== '' && $user === strtolower(trim($forbidden))) {
                throw new RuntimeException(
                    "Refusing to run tenant work as database role '{$this->configuredUser}': it owns the schema or bypasses row-level security. "
                    . 'Use the app role (the owner is for migrations only).'
                );
            }
        }
    }

    public function configuredUser(): string
    {
        return $this->configuredUser;
    }

    /** @return list<string> */
    public function forbiddenUsers(): array
    {
        return $this->forbiddenUsers;
    }
}
