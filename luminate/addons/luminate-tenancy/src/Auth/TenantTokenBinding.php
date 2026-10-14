<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Auth;

use Luminate\Tenancy\Context\ContextRejected;

/**
 * Contract §D.5 / §A.1 "token binding": a Wellms-issued user token carries a `tenant` claim that must equal the verified
 * context token's `tid`; a mismatch is 403. Enforced HERE, explicitly and before authentication, in addition to row-level
 * security (which would also hide another tenant's token row, but only as a 401 and only if the DB layer is intact).
 *
 * The claim is read without verifying the signature (Passport verifies it afterwards), so this can only ever REJECT more,
 * never authenticate anything.
 *
 * A bearer that is not JWT-shaped is left alone: it cannot be a valid Passport token, so Passport will refuse it on any
 * route that needs authentication.
 */
final class TenantTokenBinding
{
    public const CLAIM = 'tenant';

    /** @throws ContextRejected when the bearer is a JWT that is unbound to a tenant or bound to a different one */
    public function assertMatches(?string $bearer, string $tid): void
    {
        if ($bearer === null || $bearer === '') {
            return;
        }
        $claims = JwtClaims::peek($bearer);
        if ($claims === null) {
            return;
        }
        $bound = $claims[self::CLAIM] ?? null;
        if (!is_string($bound) || $bound === '') {
            throw ContextRejected::tokenUnbound();
        }
        if (!hash_equals($tid, $bound)) {
            throw ContextRejected::tokenTenantMismatch();
        }
    }
}
