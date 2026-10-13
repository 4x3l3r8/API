<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\PlacementResolver;
use PHPUnit\Framework\TestCase;

final class PlacementResolverTest extends TestCase
{
    private function resolve(array $placement, array $connections = ['shard-1' => 'pgsql'], array $buckets = ['wellms'])
    {
        $token = TokenFactory::mint(TokenFactory::claims(['placement' => $placement]));
        $ctx = (new ContextTokenVerifier([TokenFactory::KEY], 300, 30, static fn () => TokenFactory::NOW))->verify($token);

        return (new PlacementResolver($connections, $buckets))->resolve($ctx);
    }

    public function testMapsTheOpaqueConnectionNameToALaravelConnection(): void
    {
        $r = $this->resolve(['connection' => 'shard-2'], ['shard-1' => 'pgsql', 'shard-2' => 'pgsql_b']);
        $this->assertSame('pgsql_b', $r->laravelConnection);
    }

    public function testRefusesAnUnmappedConnection(): void
    {
        $this->expectException(ContextRejected::class);
        $this->resolve(['connection' => 'shard-9']);
    }

    public function testPooledTenantWithoutPrefixGetsTenantsSlashTid(): void
    {
        $r = $this->resolve(['bucket' => 'wellms']);
        $this->assertSame('wellms', $r->bucket);
        $this->assertSame('tenants/tenant-alpha', $r->s3KeyPrefix);
    }

    public function testExplicitKeyPrefixIsUsedVerbatim(): void
    {
        $r = $this->resolve(['bucket' => 'wellms/custom/area']);
        $this->assertSame('wellms', $r->bucket);
        $this->assertSame('custom/area', $r->s3KeyPrefix);
    }

    public function testDedicatedTenantWithoutPrefixHasNoPrefix(): void
    {
        $r = $this->resolve(['mode' => 'dedicated', 'bucket' => 'wellms']);
        $this->assertSame('', $r->s3KeyPrefix);
    }

    public function testRefusesABucketThatIsNotAllowListed(): void
    {
        $this->expectException(ContextRejected::class);
        $this->resolve(['bucket' => 'someone-elses-bucket/x']);
    }

    public function testCachePrefixComesFromThePlacement(): void
    {
        $this->assertSame('alpha', $this->resolve(['cache_prefix' => 'alpha'])->cachePrefix);
    }
}
