<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Queue\QueueTagger;
use Luminate\Tenancy\Queue\QueueTenantGate;
use Luminate\Tenancy\Replica\TenantReplica;
use PHPUnit\Framework\TestCase;

final class QueueTenantGateTest extends TestCase
{
    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'gate');
        $this->writeReplica([
            't-alpha' => 'active', 't-beta' => 'active', 't-gamma' => 'suspended', 't-new' => 'provisioning',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function writeReplica(array $statuses, string $connection = 'shard-1'): void
    {
        $tenants = [];
        foreach ($statuses as $tid => $status) {
            $tenants[$tid] = ['status' => $status, 'placement' => ['mode' => 'pooled', 'connection' => $connection, 'bucket' => 'wellms', 'cache_prefix' => $tid]];
        }
        file_put_contents($this->file, json_encode(['tenants' => $tenants]));
    }

    private function gate(?string $path = null): QueueTenantGate
    {
        return new QueueTenantGate(
            new QueueTagger([TokenFactory::KEY]),
            new TenantReplica($path ?? $this->file, new PlacementResolver(['shard-1' => 'pgsql'], ['wellms']))
        );
    }

    private function tagged(string $tid, string $uuid = self::UUID): array
    {
        return ['uuid' => $uuid] + (new QueueTagger([TokenFactory::KEY]))->tag($uuid, $tid);
    }

    private function assertRefused(array $payload, string $reason, ?QueueTenantGate $gate = null): void
    {
        $a = ($gate ?? $this->gate())->admit($payload);
        $this->assertFalse($a->admitted(), 'job must not be admitted');
        $this->assertNull($a->tenant, 'a refused job must never carry a tenant');
        $this->assertSame($reason, $a->refusal?->reason);
    }

    public function testAValidlyTaggedJobForAnActiveTenantRunsAsThatTenant(): void
    {
        $a = $this->gate()->admit($this->tagged('t-beta'));
        $this->assertTrue($a->admitted());
        $this->assertSame('t-beta', $a->tenant->id());
        $this->assertSame('t-beta', $a->tenant->cachePrefix);
        $this->assertSame('tenants/t-beta', $a->tenant->s3KeyPrefix);
    }

    public function testAJobWithNoTagNeverRunsAgainstAnyTenant(): void
    {
        $this->assertRefused(['uuid' => self::UUID], 'missing');
    }

    public function testAForgedTagIsRefused(): void
    {
        $p = $this->tagged('t-alpha');
        $p[QueueTagger::PAYLOAD_KEY]['sig'] = 'AAAA';
        $this->assertRefused($p, 'bad_signature');
    }

    public function testATagRelabelledForAnotherTenantIsRefused(): void
    {
        $p = $this->tagged('t-alpha');
        $p[QueueTagger::PAYLOAD_KEY]['tid'] = 't-beta';
        $this->assertRefused($p, 'bad_signature');
    }

    public function testAGenuineTagCopiedToAnotherJobIsRefused(): void
    {
        $genuine = $this->tagged('t-alpha', 'other-job-uuid')[QueueTagger::PAYLOAD_KEY];
        $this->assertRefused(['uuid' => self::UUID, QueueTagger::PAYLOAD_KEY => $genuine], 'bad_signature');
    }

    public function testASignedTagForAnUnknownTenantIsRefused(): void
    {
        $this->assertRefused($this->tagged('t-nobody'), 'unknown_tenant');
    }

    public function testSuspendedAndProvisioningTenantsDoNotRun(): void
    {
        $this->assertRefused($this->tagged('t-gamma'), 'tenant_inactive');
        $this->assertRefused($this->tagged('t-new'), 'tenant_inactive');
    }

    public function testAKnownTenantWithUnsupportedPlacementIsRefused(): void
    {
        $this->writeReplica(['t-alpha' => 'active'], 'shard-9');
        $this->assertRefused($this->tagged('t-alpha'), 'unknown_tenant');
    }

    public function testAMissingReplicaFailsClosed(): void
    {
        $this->assertRefused($this->tagged('t-alpha'), 'replica_unavailable', $this->gate('/nonexistent/tenants.json'));
    }

    public function testACorruptReplicaFailsClosed(): void
    {
        file_put_contents($this->file, '{not json');
        $this->assertRefused($this->tagged('t-alpha'), 'replica_unavailable');
    }

    public function testASuspensionIsPickedUpWithoutRestartingTheWorker(): void
    {
        $gate = $this->gate();
        $this->assertTrue($gate->admit($this->tagged('t-alpha'))->admitted());

        $this->writeReplica(['t-alpha' => 'suspended', 't-beta' => 'active']);
        touch($this->file, time() + 5); // a different mtime, as a rewritten snapshot would have
        $this->assertRefused($this->tagged('t-alpha'), 'tenant_inactive', $gate);
        $this->assertTrue($gate->admit($this->tagged('t-beta'))->admitted());
    }
}
