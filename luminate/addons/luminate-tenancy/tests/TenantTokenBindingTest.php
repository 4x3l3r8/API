<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Auth\TenantTokenBinding;
use Luminate\Tenancy\Context\ContextRejected;
use PHPUnit\Framework\TestCase;

final class TenantTokenBindingTest extends TestCase
{
    private function jwt(array $claims): string
    {
        $b = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b('{"alg":"RS256","typ":"JWT"}') . '.' . $b(json_encode($claims)) . '.' . $b('sig');
    }

    private function assertRejected(callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fail('expected a 403');
        } catch (ContextRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(403, $e->httpStatus);
        }
    }

    public function testATokenForThisTenantPasses(): void
    {
        (new TenantTokenBinding())->assertMatches($this->jwt(['tenant' => 't-alpha', 'sub' => '1']), 't-alpha');
        $this->addToAssertionCount(1);
    }

    public function testATokenForAnotherTenantIsA403(): void
    {
        $this->assertRejected(fn () => (new TenantTokenBinding())->assertMatches($this->jwt(['tenant' => 't-alpha']), 't-beta'), 'token_tenant_mismatch');
    }

    public function testATokenWithNoTenantClaimIsA403(): void
    {
        $this->assertRejected(fn () => (new TenantTokenBinding())->assertMatches($this->jwt(['sub' => '1']), 't-alpha'), 'token_unbound');
    }

    public function testNonStringOrEmptyClaimsAreUnbound(): void
    {
        foreach ([['tenant' => ''], ['tenant' => 5], ['tenant' => ['t-alpha']], ['tenant' => null], ['tenant' => true]] as $claims) {
            $this->assertRejected(fn () => (new TenantTokenBinding())->assertMatches($this->jwt($claims), 't-alpha'), 'token_unbound');
        }
    }

    public function testTenantIdsAreComparedExactly(): void
    {
        foreach (['T-ALPHA', 't-alpha ', 't-alph', 't-alpha2'] as $other) {
            $this->assertRejected(fn () => (new TenantTokenBinding())->assertMatches($this->jwt(['tenant' => $other]), 't-alpha'), 'token_tenant_mismatch');
        }
    }

    public function testNoBearerOrANonJwtBearerIsLeftToPassport(): void
    {
        $b = new TenantTokenBinding();
        foreach ([null, '', 'opaque-api-key', 'a.b', 'a.b.c.d'] as $bearer) {
            $b->assertMatches($bearer, 't-alpha');
        }
        $this->addToAssertionCount(1);
    }
}
