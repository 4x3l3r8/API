<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Replica\TenantReplica;
use Luminate\Tenancy\Scheduling\ActiveTenantSelector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActiveTenantSelectorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'sel');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function replica(array $tenants, ?string $path = null): TenantReplica
    {
        $entries = [];
        foreach ($tenants as $tid => [$status, $connection]) {
            $entries[$tid] = ['status' => $status, 'placement' => ['mode' => 'pooled', 'connection' => $connection, 'bucket' => 'wellms', 'cache_prefix' => $tid]];
        }
        file_put_contents($this->file, json_encode(['tenants' => $entries]));

        return new TenantReplica($path ?? $this->file, new PlacementResolver(['shard-1' => 'pgsql'], ['wellms']));
    }

    public function testOnlyActiveTenantsRun(): void
    {
        $r = (new ActiveTenantSelector())->select($this->replica([
            't-alpha' => ['active', 'shard-1'],
            't-beta' => ['active', 'shard-1'],
            't-gamma' => ['suspended', 'shard-1'],
            't-new' => ['provisioning', 'shard-1'],
        ]));

        $this->assertSame(['t-alpha', 't-beta'], $r['run']);
        $this->assertSame(['t-gamma' => 'status suspended', 't-new' => 'status provisioning'], $r['skipped']);
    }

    public function testAnActiveTenantWithUnsupportedPlacementIsSkippedWithAReason(): void
    {
        $r = (new ActiveTenantSelector())->select($this->replica(['t-alpha' => ['active', 'shard-1'], 't-odd' => ['active', 'shard-9']]));
        $this->assertSame(['t-alpha'], $r['run']);
        $this->assertSame(['t-odd' => 'placement_unsupported'], $r['skipped']);
    }

    public function testAnEmptyReplicaRunsNothing(): void
    {
        $r = (new ActiveTenantSelector())->select($this->replica([]));
        $this->assertSame([], $r['run']);
    }

    public function testAnUnreadableReplicaThrowsSoTheWholeTickIsSkipped(): void
    {
        $this->expectException(RuntimeException::class);
        (new ActiveTenantSelector())->select($this->replica([], '/nonexistent/tenants.json'));
    }

    public function testACorruptReplicaThrows(): void
    {
        $replica = $this->replica(['t-alpha' => ['active', 'shard-1']]);
        file_put_contents($this->file, '{broken');
        $this->expectException(\Throwable::class);
        (new ActiveTenantSelector())->select($replica);
    }

    public function testAStatusChangeIsPickedUpOnTheNextTick(): void
    {
        $sel = new ActiveTenantSelector();
        $replica = $this->replica(['t-alpha' => ['active', 'shard-1']]);
        $this->assertSame(['t-alpha'], $sel->select($replica)['run']);

        $this->replica(['t-alpha' => ['suspended', 'shard-1']]);
        touch($this->file, time() + 5);
        $this->assertSame([], $sel->select($replica)['run']);
    }
}
