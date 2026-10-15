<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Registry\Reconciler;
use Luminate\Tenancy\Registry\RegistryAuth;
use Luminate\Tenancy\Registry\RegistryFetcher;
use Luminate\Tenancy\Registry\RegistryRejected;
use Luminate\Tenancy\Replica\DatabaseReplicaSource;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const P = ['mode' => 'pooled', 'connection' => 'shard-1', 'bucket' => 'wellms', 'cache_prefix' => 'x'];

    private InMemoryRegistryStore $store;
    private RegistryAuth $auth;
    /** @var array{status:int,body:string,timestamp:?string,signature:?string} */
    private array $response;

    protected function setUp(): void
    {
        $this->store = new InMemoryRegistryStore();
        $this->auth = new RegistryAuth(['k'], 300, fn () => self::NOW);
    }

    private function reconciler(): Reconciler
    {
        $fetcher = new class($this) implements RegistryFetcher {
            public function __construct(private readonly ReconcilerTest $t)
            {
            }

            public function fetch(): array
            {
                return $this->t->response();
            }
        };

        return new Reconciler($fetcher, $this->store, $this->auth, 120, fn () => self::NOW);
    }

    public function response(): array
    {
        return $this->response;
    }

    private function serve(array|string $doc, bool $sign = true, int $status = 200): void
    {
        $body = is_string($doc) ? $doc : json_encode($doc);
        $ts = (string) self::NOW;
        $this->response = ['status' => $status, 'body' => $body, 'timestamp' => $ts, 'signature' => $sign ? $this->auth->signResponse($ts, $body) : 'sha256=' . str_repeat('0', 64)];
    }

    private static function t(string $tid, string $status, int $rev): array
    {
        return ['tenant_id' => $tid, 'status' => $status, 'rev' => $rev, 'placement' => ['cache_prefix' => $tid] + self::P];
    }

    private function failure(bool $allowEmpty = false): string
    {
        try {
            $this->reconciler()->run($allowEmpty);
        } catch (\RuntimeException $e) {
            return $e instanceof RegistryRejected ? $e->reason . ': ' . $e->getMessage() : $e->getMessage();
        }

        return 'ok';
    }

    public function testConvergesOnTheAuthoritativeList(): void
    {
        $this->store->apply('t-old', ['status' => 'active', 'rev' => 1, 'placement' => self::P], 'seed', self::NOW - 10_000);
        $this->serve(['as_of' => self::NOW, 'tenants' => [self::t('t-a', 'active', 3), self::t('t-b', 'suspended', 2)]]);

        $r = $this->reconciler()->run();

        $this->assertSame(['t-a', 't-b'], $r['applied']);
        $this->assertSame(['t-old'], $r['removed']);
        $this->assertSame('suspended', $this->store->find('t-b')['status']);
        $this->assertSame('removed', $this->store->find('t-old')['status']);
        $this->assertSame([['ok' => true, 'error' => null, 'as_of' => self::NOW]], $this->store->pulls);
    }

    public function testAPullCutBeforeAPushNeverUndoesIt(): void
    {
        $this->store->apply('t-a', ['status' => 'suspended', 'rev' => 9, 'placement' => ['cache_prefix' => 't-a'] + self::P], 'push', self::NOW);
        $this->serve(['as_of' => self::NOW - 5, 'tenants' => [self::t('t-a', 'active', 8)]]);

        $r = $this->reconciler()->run();

        $this->assertSame(['t-a'], $r['stale']);
        $this->assertSame('suspended', $this->store->find('t-a')['status']);
    }

    public function testATenantPushedAfterTheSnapshotWasCutIsNotTombstonedByIt(): void
    {
        $this->store->apply('t-new', ['status' => 'provisioning', 'rev' => 1, 'placement' => ['cache_prefix' => 't-new'] + self::P], 'push', self::NOW - 30);
        $this->serve(['as_of' => self::NOW - 60, 'tenants' => [self::t('t-a', 'active', 1)]]);

        $r = $this->reconciler()->run();

        $this->assertSame([], $r['removed']);
        $this->assertSame('provisioning', $this->store->find('t-new')['status']);
    }

    public function testTombstonesNeverDeleteRows(): void
    {
        $this->store->apply('t-old', ['status' => 'active', 'rev' => 1, 'placement' => self::P], 'seed', self::NOW - 10_000);
        $this->serve(['as_of' => self::NOW, 'tenants' => [self::t('t-a', 'active', 1)]]);
        $this->reconciler()->run();
        $this->assertNotNull($this->store->find('t-old'));
        // and the replica no longer exposes it: to every consumer it is unknown (fail closed)
        $this->assertArrayNotHasKey('t-old', (new DatabaseReplicaSource($this->store, 0))->entries());
        // while the listing bringing it back at the same rev revives it
        $this->serve(['as_of' => self::NOW, 'tenants' => [self::t('t-old', 'active', 1)]]);
        $this->reconciler()->run();
        $this->assertSame('active', $this->store->find('t-old')['status']);
    }

    public function testAnEmptyListIsRefusedUnlessAsked(): void
    {
        $this->store->apply('t-a', ['status' => 'active', 'rev' => 1, 'placement' => self::P], 'seed', self::NOW - 10_000);
        $this->serve(['as_of' => self::NOW, 'tenants' => []]);

        $this->assertStringContainsString('lists no tenants', $this->failure());
        $this->assertSame('active', $this->store->find('t-a')['status'], 'refusing must leave the replica untouched');
        $this->assertSame('ok', $this->failure(true));
        $this->assertSame('removed', $this->store->find('t-a')['status']);
    }

    /** @dataProvider rejectedResponses */
    public function testAnythingUntrustworthyLeavesTheReplicaUntouched(callable $arrange, string $needle): void
    {
        $this->store->apply('t-a', ['status' => 'active', 'rev' => 1, 'placement' => self::P], 'seed', self::NOW - 10_000);
        $before = $this->store->rows;
        $arrange($this);

        $this->assertStringContainsString($needle, $this->failure());
        $this->assertSame($before, $this->store->rows);
        $this->assertFalse(end($this->store->pulls)['ok']);
    }

    public static function rejectedResponses(): array
    {
        $good = fn (ReconcilerTest $t) => ['as_of' => self::NOW, 'tenants' => [self::t('t-a', 'suspended', 5)]];

        return [
            'forged signature' => [fn (ReconcilerTest $t) => $t->serveBody($good($t), false), 'registry_signature_invalid'],
            'http 500' => [fn (ReconcilerTest $t) => $t->serveBody($good($t), true, 500), 'HTTP 500'],
            'not json' => [fn (ReconcilerTest $t) => $t->serveBody('<html>', true), 'not JSON'],
            'no as_of' => [fn (ReconcilerTest $t) => $t->serveBody(['tenants' => [self::t('t-a', 'active', 1)]]), 'as_of'],
            'future as_of' => [fn (ReconcilerTest $t) => $t->serveBody(['as_of' => self::NOW + 1000, 'tenants' => [self::t('t-a', 'active', 1)]]), 'future'],
            'tenants not a list' => [fn (ReconcilerTest $t) => $t->serveBody(['as_of' => self::NOW, 'tenants' => ['t-a' => self::t('t-a', 'active', 1)]]), 'as_of'],
            'one bad entry spoils the pull' => [fn (ReconcilerTest $t) => $t->serveBody(['as_of' => self::NOW, 'tenants' => [self::t('t-a', 'suspended', 5), ['tenant_id' => 't-b', 'status' => 'bogus', 'rev' => 1, 'placement' => self::P]]]), "entry 't-b'"],
            'bad tenant id' => [fn (ReconcilerTest $t) => $t->serveBody(['as_of' => self::NOW, 'tenants' => [self::t('../x', 'active', 1)]]), 'tenant_id'],
            'duplicate tenant' => [fn (ReconcilerTest $t) => $t->serveBody(['as_of' => self::NOW, 'tenants' => [self::t('t-a', 'active', 1), self::t('t-a', 'suspended', 2)]]), 'twice'],
        ];
    }

    public function serveBody(array|string $doc, bool $sign = true, int $status = 200): void
    {
        $this->serve($doc, $sign, $status);
    }

    public function testATransportFailureIsRecordedAndRethrown(): void
    {
        $fetcher = new class implements RegistryFetcher {
            public function fetch(): array
            {
                throw new \RuntimeException('registry pull transport failure: ConnectException');
            }
        };
        $this->expectException(\RuntimeException::class);
        try {
            (new Reconciler($fetcher, $this->store, $this->auth, 120, fn () => self::NOW))->run();
        } finally {
            $this->assertFalse($this->store->pulls[0]['ok']);
        }
    }

    public function testTheReplicaCacheBoundsStalenessToItsTtl(): void
    {
        $clock = self::NOW;
        $source = new DatabaseReplicaSource($this->store, 5, function () use (&$clock) {
            return $clock;
        });
        $this->store->apply('t-a', ['status' => 'active', 'rev' => 1, 'placement' => self::P], 'push', $clock);
        $this->assertSame('active', $source->entries()['t-a']['status']);

        $this->store->apply('t-a', ['status' => 'suspended', 'rev' => 2, 'placement' => self::P], 'push', $clock);
        $clock += 4;
        $this->assertSame('active', $source->entries()['t-a']['status'], 'inside the ttl: cached');
        $clock += 1;
        $this->assertSame('suspended', $source->entries()['t-a']['status'], 'at the ttl: re-read');
    }
}
