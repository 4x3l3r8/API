<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Replica\TenantReplica;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantReplicaTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'replica');
        file_put_contents($this->file, json_encode(['version' => 1, 'tenants' => [
            't-alpha' => ['status' => 'active', 'placement' => ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 't-alpha']],
            't-new' => ['status' => 'provisioning', 'placement' => ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 't-new']],
            't-off' => ['status' => 'suspended', 'placement' => ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 't-off']],
            't-odd' => ['status' => 'active', 'placement' => ['mode' => 'pooled', 'connection' => 'shard-9', 'bucket' => 'wellms', 'cache_prefix' => 't-odd']],
        ]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function replica(?string $path = null): TenantReplica
    {
        return new TenantReplica($path ?? $this->file, new PlacementResolver(['shard-1' => 'pgsql'], ['wellms']));
    }

    public function testResolvesAnActiveTenant(): void
    {
        $r = $this->replica()->resolve('t-alpha');
        $this->assertSame('t-alpha', $r->id());
        $this->assertSame('pgsql', $r->laravelConnection);
        $this->assertSame('tenants/t-alpha', $r->s3KeyPrefix);
    }

    public function testWorkersAcceptOnlyActiveByDefault(): void
    {
        $this->expectException(ContextRejected::class);
        $this->replica()->resolve('t-new');
    }

    public function testProvisioningMayAcceptProvisioningStatus(): void
    {
        $this->assertSame('t-new', $this->replica()->resolve('t-new', ['provisioning', 'active'])->id());
    }

    public function testSuspendedIsRefusedEvenForProvisioning(): void
    {
        $this->expectException(ContextRejected::class);
        $this->replica()->resolve('t-off', ['provisioning', 'active']);
    }

    public function testUnknownTenantIsRefused(): void
    {
        $this->expectException(ContextRejected::class);
        $this->replica()->resolve('t-nope');
    }

    public function testUnmappedPlacementIsRefused(): void
    {
        $this->expectException(ContextRejected::class);
        $this->replica()->resolve('t-odd');
    }

    public function testMissingSnapshotFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->replica('/nonexistent/tenants.json')->ids();
    }

    public function testIncompleteEntryFailsClosed(): void
    {
        file_put_contents($this->file, json_encode(['tenants' => ['t-x' => ['status' => 'active']]]));
        $this->expectException(RuntimeException::class);
        $this->replica()->ids();
    }

    public function testListsIdsAndStatus(): void
    {
        $r = $this->replica();
        $this->assertSame(['t-alpha', 't-new', 't-off', 't-odd'], $r->ids());
        $this->assertSame('suspended', $r->status('t-off'));
        $this->assertNull($r->status('t-nope'));
    }
}
