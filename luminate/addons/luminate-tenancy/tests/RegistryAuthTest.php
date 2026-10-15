<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Registry\RegistryAuth;
use Luminate\Tenancy\Registry\RegistryRejected;
use PHPUnit\Framework\TestCase;

final class RegistryAuthTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function auth(array $keys = ['key-current', 'key-previous']): RegistryAuth
    {
        return new RegistryAuth($keys, 300, fn () => self::NOW);
    }

    private function reason(callable $f): string
    {
        try {
            $f();
        } catch (RegistryRejected $e) {
            return $e->reason;
        }

        return 'accepted';
    }

    public function testAcceptsAGoodSignatureFromEitherKey(): void
    {
        $a = $this->auth();
        $ts = (string) self::NOW;
        foreach (['key-current', 'key-previous'] as $key) {
            $sig = $a->sign($ts, 'PUT', '/api/luminate/registry/tenants/t-a', '{"x":1}', $key);
            $this->assertSame('accepted', $this->reason(fn () => $a->verifyRequest($ts, $sig, 'PUT', '/api/luminate/registry/tenants/t-a', '{"x":1}')));
        }
    }

    public function testRefusesAnotherKey(): void
    {
        $a = $this->auth();
        $ts = (string) self::NOW;
        $sig = $a->sign($ts, 'PUT', '/p', 'b', 'some-other-key');
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyRequest($ts, $sig, 'PUT', '/p', 'b')));
    }

    public function testTheSignatureCoversMethodPathAndBody(): void
    {
        $a = $this->auth();
        $ts = (string) self::NOW;
        $sig = $a->sign($ts, 'PUT', '/api/luminate/registry/tenants/t-a', 'body');
        foreach ([['GET', '/api/luminate/registry/tenants/t-a', 'body'], ['PUT', '/api/luminate/registry/tenants/t-b', 'body'], ['PUT', '/api/luminate/registry/tenants/t-a', 'body2']] as [$m, $p, $b]) {
            $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyRequest($ts, $sig, $m, $p, $b)), "$m $p $b");
        }
    }

    public function testTimestampWindowBothWays(): void
    {
        $a = $this->auth();
        foreach ([self::NOW - 301, self::NOW + 301] as $t) {
            $sig = $a->sign((string) $t, 'GET', '/p', '');
            $this->assertSame('registry_timestamp_expired', $this->reason(fn () => $a->verifyRequest((string) $t, $sig, 'GET', '/p', '')));
        }
        $t = self::NOW - 300;
        $this->assertSame('accepted', $this->reason(fn () => $a->verifyRequest((string) $t, $a->sign((string) $t, 'GET', '/p', ''), 'GET', '/p', '')));
    }

    public function testMalformedAndMissingInputsAreRefusedNotCrashed(): void
    {
        $a = $this->auth();
        $this->assertSame('registry_signature_missing', $this->reason(fn () => $a->verifyRequest(null, null, 'GET', '/p', '')));
        $this->assertSame('registry_timestamp_invalid', $this->reason(fn () => $a->verifyRequest('12abc', 'sha256=' . str_repeat('a', 64), 'GET', '/p', '')));
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyRequest((string) self::NOW, 'nonsense', 'GET', '/p', '')));
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyRequest((string) self::NOW, 'sha256=' . strtoupper(str_repeat('a', 64)), 'GET', '/p', '')));
    }

    public function testNoKeyMeansDisabledNotOpen(): void
    {
        $a = $this->auth([]);
        $this->assertFalse($a->enabled());
        $this->assertSame('registry_disabled', $this->reason(fn () => $a->verifyRequest((string) self::NOW, 'sha256=' . str_repeat('a', 64), 'GET', '/p', '')));
    }

    public function testResponsesAreSignedInADifferentDomainFromRequests(): void
    {
        $a = $this->auth();
        $ts = (string) self::NOW;
        $resp = $a->signResponse($ts, 'body');
        $this->assertSame('accepted', $this->reason(fn () => $a->verifyResponse($ts, $resp, 'body')));
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyResponse($ts, $resp, 'tampered')));
        // a captured request signature cannot be passed off as a signed registry listing, nor the reverse
        $req = $a->sign($ts, 'GET', '/p', 'body');
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyResponse($ts, $req, 'body')));
        $this->assertSame('registry_signature_invalid', $this->reason(fn () => $a->verifyRequest($ts, $resp, 'GET', '/p', 'body')));
    }

    /**
     * The documented test vectors (README "Registry sync wire shape"). Luminate signs against these; if this test changes, the
     * contract changed. Message = "{ts}.{METHOD}.{path}.{sha256hex(body)}" over the RAW body bytes; path has no query string.
     */
    public function testPublishedVectors(): void
    {
        $a = new RegistryAuth(['registry-test-key'], 300, fn () => 1_800_000_000);
        $body = '{"status":"active","rev":7,"placement":{"mode":"pooled","connection":"shard-1","bucket":"wellms","cache_prefix":"t-demo"}}';
        $this->assertSame('c29cb0624cd419a5477435efe0bd114fec568f148a5fbb1f6dbb1036cce6a9c9', hash('sha256', $body));
        $this->assertSame(
            'sha256=d2b4a9a7ca25e01f057991a0d10c365e56cb0c6661640d83d1b633c982173988',
            $a->sign('1800000000', 'PUT', '/api/luminate/registry/tenants/t-demo', $body)
        );
        // the pull request: GET, empty body (sha256 of the empty string), the path of the configured pull URL
        $this->assertSame('sha256=a2b3f92a8ac8017c764d504e8952a2c48eb8d63e2c1429d79c4635141c718e6c', $a->sign('1800000000', 'GET', '/registry/tenants', ''));
        // the pull RESPONSE: its own domain ("response." prefix), over the exact response body
        $this->assertSame('sha256=0ff6f84d73db4cad80d43c45587ff33198b271283c14bb3bb04f61d099bf3784', $a->signResponse('1800000000', '{"as_of":1800000000,"tenants":[]}'));
    }
}
