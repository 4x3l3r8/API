<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Registry\RegistryPayload;
use Luminate\Tenancy\Registry\RegistryRejected;
use Luminate\Tenancy\Registry\RegistryRules;
use PHPUnit\Framework\TestCase;

final class RegistryRulesTest extends TestCase
{
    private const P = ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 't-a'];

    private static function e(string $status, int $rev, array $p = self::P, string $source = 'push'): array
    {
        return ['status' => $status, 'rev' => $rev, 'placement' => $p, 'source' => $source];
    }

    public function testFirstSightIsApplied(): void
    {
        $this->assertSame('applied', RegistryRules::decide(null, self::e('active', 0)));
    }

    public function testNewerRevIsApplied(): void
    {
        $this->assertSame('applied', RegistryRules::decide(self::e('active', 3), self::e('suspended', 4)));
    }

    public function testALateActiveNeverUndoesANewerSuspension(): void
    {
        $this->assertSame('stale', RegistryRules::decide(self::e('suspended', 5), self::e('active', 4)));
    }

    public function testSameRevSameContentIsUnchanged(): void
    {
        $this->assertSame('unchanged', RegistryRules::decide(self::e('active', 5), self::e('active', 5)));
    }

    public function testSameRevDifferentContentIsAConflict(): void
    {
        $this->assertSame('conflict', RegistryRules::decide(self::e('active', 5), self::e('suspended', 5)));
        $other = ['mode' => 'pooled', 'connection' => 'shard-2', 'bucket' => 'wellms', 'cache_prefix' => 't-a'];
        $this->assertSame('conflict', RegistryRules::decide(self::e('active', 5), self::e('active', 5, $other)));
    }

    public function testAnExplicitRemovalNeedsAStrictlyNewerRevToUndo(): void
    {
        $removed = self::e('removed', 7, self::P, 'push');
        $this->assertSame('stale', RegistryRules::decide($removed, self::e('active', 7)));
        $this->assertSame('stale', RegistryRules::decide($removed, self::e('active', 6)));
        $this->assertSame('applied', RegistryRules::decide($removed, self::e('active', 8)));
    }

    public function testAnAbsenceTombstoneIsLiftedByTheListingItAtTheSameRev(): void
    {
        $gone = self::e('removed', 7, self::P, 'absent');
        $this->assertSame('applied', RegistryRules::decide($gone, self::e('active', 7)));
        $this->assertSame('stale', RegistryRules::decide($gone, self::e('active', 6)));
    }

    public function testRemovedStaysRemovedWhenRemovedAgain(): void
    {
        $this->assertSame('unchanged', RegistryRules::decide(self::e('removed', 7), self::e('removed', 7)));
    }

    public function testPayloadParsesAValidEntryAndDefaultsRevOnlyWhenAsked(): void
    {
        $ok = RegistryPayload::parse(['status' => 'active', 'rev' => 2, 'placement' => self::P]);
        $this->assertSame(2, $ok['rev']);
        $this->assertSame(0, RegistryPayload::parse(['status' => 'active', 'placement' => self::P], false)['rev']);
        $this->expectException(RegistryRejected::class);
        RegistryPayload::parse(['status' => 'active', 'placement' => self::P]);
    }

    /** @dataProvider badEntries */
    public function testPayloadRefusesWhatCouldNotRideInAToken(array $entry, string $needle): void
    {
        try {
            RegistryPayload::parse($entry);
            $this->fail('accepted');
        } catch (RegistryRejected $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public static function badEntries(): array
    {
        $p = self::P;

        return [
            'unknown status' => [['status' => 'deleted', 'rev' => 1, 'placement' => $p], 'status'],
            'negative rev' => [['status' => 'active', 'rev' => -1, 'placement' => $p], 'rev'],
            'string rev' => [['status' => 'active', 'rev' => '1', 'placement' => $p], 'rev'],
            'no placement' => [['status' => 'active', 'rev' => 1], 'placement'],
            'bad mode' => [['status' => 'active', 'rev' => 1, 'placement' => ['mode' => 'shared'] + $p], 'mode'],
            'dsn as connection' => [['status' => 'active', 'rev' => 1, 'placement' => ['connection' => 'pgsql://u:p@h/db'] + $p], 'connection'],
            'traversal bucket' => [['status' => 'active', 'rev' => 1, 'placement' => ['bucket' => 'a/../b'] + $p], 'bucket'],
            'bad cache prefix' => [['status' => 'active', 'rev' => 1, 'placement' => ['cache_prefix' => '-x'] + $p], 'cache_prefix'],
            'a list' => [['x'], 'object'],
        ];
    }

    public function testTenantIdsFollowTheTokenRule(): void
    {
        $this->assertTrue(RegistryPayload::validTenantId('t-alpha'));
        $this->assertFalse(RegistryPayload::validTenantId('../x'));
        $this->assertFalse(RegistryPayload::validTenantId(''));
        $this->assertFalse(RegistryPayload::validTenantId(str_repeat('a', 65)));
        $this->assertFalse(RegistryPayload::validTenantId(12));
    }
}
