<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Webhooks\Backoff;
use Luminate\Tenancy\Webhooks\Delivery;
use Luminate\Tenancy\Webhooks\DeliveryOutcome;
use Luminate\Tenancy\Webhooks\DeliveryService;
use Luminate\Tenancy\Webhooks\DeliveryStore;
use Luminate\Tenancy\Webhooks\SendResult;
use Luminate\Tenancy\Webhooks\Sender;
use Luminate\Tenancy\Webhooks\WebhookSettings;
use Luminate\Tenancy\Webhooks\WebhookSigner;
use PHPUnit\Framework\TestCase;

/** In-memory outbox that enforces the same claim rules as the SQL (pending + due, lease, attempts, first-send timestamp). */
final class MemoryStore implements DeliveryStore
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    private int $next = 1;

    public function insert(string $deliveryId, string $event, string $body, int $now): int
    {
        $id = $this->next++;
        $this->rows[$id] = ['id' => $id, 'delivery_id' => $deliveryId, 'event' => $event, 'body' => $body, 'status' => 'pending', 'attempts' => 0,
            'next' => $now, 'first' => null, 'created' => $now, 'last_status' => null, 'last_error' => null, 'delivered_at' => null, 'updated' => $now];

        return $id;
    }

    public function claim(int $id, int $leaseSeconds, int $now): ?Delivery
    {
        $r = &$this->rows[$id];
        if (!isset($r) || $r['status'] !== 'pending' || $r['next'] > $now) {
            return null;
        }
        $r['attempts']++;
        $r['next'] = $now + $leaseSeconds;
        $r['first'] ??= $now;
        $r['updated'] = $now;

        return new Delivery($id, $r['delivery_id'], $r['event'], $r['body'], $r['attempts'], $r['first'], $r['created']);
    }

    public function markDelivered(int $id, int $httpStatus, int $now): void
    {
        $this->rows[$id] = ['status' => 'delivered', 'last_status' => $httpStatus, 'last_error' => null, 'delivered_at' => $now, 'updated' => $now] + $this->rows[$id];
    }

    public function markFailed(int $id, ?int $httpStatus, string $error, int $nextAttemptAt, int $now): void
    {
        $this->rows[$id] = ['last_status' => $httpStatus, 'last_error' => $error, 'next' => $nextAttemptAt, 'updated' => $now] + $this->rows[$id];
    }

    public function markDead(int $id, ?int $httpStatus, string $error, int $now): void
    {
        $this->rows[$id] = ['status' => 'dead', 'last_status' => $httpStatus, 'last_error' => $error, 'updated' => $now] + $this->rows[$id];
    }

    public function dueIds(int $now, int $limit): array
    {
        $ids = array_keys(array_filter($this->rows, fn ($r) => $r['status'] === 'pending' && $r['next'] <= $now));

        return array_slice($ids, 0, $limit);
    }

    public function prune(int $deliveredBefore, int $deadBefore): int
    {
        $n = 0;
        foreach ($this->rows as $id => $r) {
            if (($r['status'] === 'delivered' && $r['delivered_at'] < $deliveredBefore) || ($r['status'] === 'dead' && $r['updated'] < $deadBefore)) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }
}

/** Scripted receiver: pops one result per call and remembers exactly what it was sent. */
final class ScriptedSender implements Sender
{
    /** @var list<array{url:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    /** @param list<SendResult> $script */
    public function __construct(private array $script)
    {
    }

    public function post(string $url, string $body, array $headers, int $timeout, int $connectTimeout): SendResult
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        return array_shift($this->script) ?? new SendResult(200);
    }
}

final class DeliveryServiceTest extends TestCase
{
    private const SECRET = 'a-webhook-secret-of-sufficient-length';
    private const BODY = '{"event":"quiz.attempt.finished","tenant_id":"t-alpha","data":{"attempt_id":9}}';
    private int $now = 1_800_000_000;

    private function service(MemoryStore $store, Sender $sender, ?WebhookSettings $settings = null): DeliveryService
    {
        return new DeliveryService(
            $store,
            $sender,
            $settings ?? new WebhookSettings('https://luminate.example/hooks', self::SECRET),
            new Backoff([60, 300, 1800, 7200, 21600], 86400),
            fn () => $this->now,
            120
        );
    }

    private function recorded(MemoryStore $store): int
    {
        return $store->insert('6f1c2a9e-0b7d-4c1e-9d55-3f0a1b2c3d4e', 'quiz.attempt.finished', self::BODY, $this->now);
    }

    public function testASuccessfulFirstAttemptIsSignedAndMarkedDelivered(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $sender = new ScriptedSender([new SendResult(204)]);

        $out = $this->service($store, $sender)->run($id);

        $this->assertSame(DeliveryOutcome::DELIVERED, $out->kind);
        $this->assertSame('delivered', $store->rows[$id]['status']);
        $this->assertSame(204, $store->rows[$id]['last_status']);
        $this->assertCount(1, $sender->calls);
        $h = $sender->calls[0]['headers'];
        $this->assertSame('quiz.attempt.finished', $h['X-Wellms-Event']);
        $this->assertSame('6f1c2a9e-0b7d-4c1e-9d55-3f0a1b2c3d4e', $h['X-Wellms-Delivery']);
        $this->assertSame((string) $this->now, $h['X-Wellms-Timestamp']);
        $this->assertTrue(WebhookSigner::verify($h['X-Wellms-Signature'], self::SECRET, $h['X-Wellms-Delivery'], (int) $h['X-Wellms-Timestamp'], $sender->calls[0]['body']));
        $this->assertSame(self::BODY, $sender->calls[0]['body'], 'the raw body is sent byte for byte');
        $this->assertSame('application/json', $h['Content-Type']);
    }

    public function testARetryReusesTheSameDeliveryIdTimestampBodyAndSignature(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $sender = new ScriptedSender([new SendResult(500), new SendResult(null, 'timeout'), new SendResult(200)]);
        $svc = $this->service($store, $sender);

        $this->assertSame(DeliveryOutcome::RETRY, $svc->run($id)->kind);
        $this->now += 60;
        $this->assertSame(DeliveryOutcome::RETRY, $svc->run($id)->kind);
        $this->now += 300;
        $this->assertSame(DeliveryOutcome::DELIVERED, $svc->run($id)->kind);

        $this->assertCount(3, $sender->calls);
        $first = $sender->calls[0];
        foreach ($sender->calls as $c) {
            $this->assertSame($first['body'], $c['body']);
            $this->assertSame($first['headers']['X-Wellms-Delivery'], $c['headers']['X-Wellms-Delivery']);
            $this->assertSame($first['headers']['X-Wellms-Timestamp'], $c['headers']['X-Wellms-Timestamp'], 'timestamp is the FIRST send, not each send');
            $this->assertSame($first['headers']['X-Wellms-Signature'], $c['headers']['X-Wellms-Signature'], 'so the signature is stable too');
        }
        $this->assertSame(3, $store->rows[$id]['attempts']);
    }

    public function testFailuresFollowTheContractBackoffAndAreNotDueEarly(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $svc = $this->service($store, new ScriptedSender(array_fill(0, 10, new SendResult(503))));

        $expected = [60, 300, 1800, 7200, 21600];
        foreach ($expected as $delay) {
            $out = $svc->run($id);
            $this->assertSame(DeliveryOutcome::RETRY, $out->kind);
            $this->assertSame($delay, $out->retryIn);
            $this->assertSame($this->now + $delay, $store->rows[$id]['next']);
            $this->assertSame(DeliveryOutcome::SKIPPED, $svc->run($id)->kind, 'not due yet: a second worker or the sweeper must not send it early');
            $this->now += $delay;
        }
    }

    public function testExhaustingTheWindowDeadLettersTheDeliveryAndStopsSending(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $sender = new ScriptedSender(array_fill(0, 50, new SendResult(500)));
        $svc = $this->service($store, $sender);

        $last = null;
        for ($i = 0; $i < 20; $i++) {
            $last = $svc->run($id);
            if ($last->kind === DeliveryOutcome::DEAD) {
                break;
            }
            $this->now = $store->rows[$id]['next'];
        }

        $this->assertSame(DeliveryOutcome::DEAD, $last?->kind);
        $this->assertSame('dead', $store->rows[$id]['status']);
        $this->assertSame(8, $store->rows[$id]['attempts'], '8 attempts over about 20.6 h with the default schedule');
        $sent = count($sender->calls);
        $this->now += 999_999;
        $this->assertSame(DeliveryOutcome::SKIPPED, $svc->run($id)->kind, 'a dead delivery is never sent again');
        $this->assertCount($sent, $sender->calls);
        $this->assertNotContains($id, $store->dueIds($this->now, 10));
    }

    public function testAnythingButA2xxIsAFailure(): void
    {
        foreach ([301, 302, 400, 401, 404, 410, 429, 500, 502] as $status) {
            $store = new MemoryStore();
            $id = $this->recorded($store);
            $out = $this->service($store, new ScriptedSender([new SendResult($status)]))->run($id);
            $this->assertSame(DeliveryOutcome::RETRY, $out->kind, "HTTP $status must be retried, not accepted");
            $this->assertSame('pending', $store->rows[$id]['status']);
        }
        foreach ([200, 201, 202, 204] as $status) {
            $store = new MemoryStore();
            $id = $this->recorded($store);
            $this->assertSame(DeliveryOutcome::DELIVERED, $this->service($store, new ScriptedSender([new SendResult($status)]))->run($id)->kind);
        }
    }

    public function testADeliveredOrDeadRowIsNeverClaimedAgain(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $sender = new ScriptedSender([new SendResult(200)]);
        $svc = $this->service($store, $sender);
        $svc->run($id);
        $this->now += 100_000;
        $this->assertSame(DeliveryOutcome::SKIPPED, $svc->run($id)->kind);
        $this->assertCount(1, $sender->calls, 'at most one successful send');
    }

    public function testTwoWorkersCannotSendTheSameAttempt(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $a = $store->claim($id, 120, $this->now);
        $b = $store->claim($id, 120, $this->now);

        $this->assertNotNull($a);
        $this->assertNull($b, 'the lease makes the second claim fail');
    }

    public function testACrashedWorkersLeaseExpiresAndTheRowIsRecoveredAndCounted(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $store->claim($id, 120, $this->now);                    // worker takes it, then dies before reporting
        $this->assertSame([], $store->dueIds($this->now + 119, 10), 'still leased');
        $this->assertSame([$id], $store->dueIds($this->now + 120, 10), 'lease expired: the sweeper finds it');

        $this->now += 120;
        $sender = new ScriptedSender([new SendResult(200)]);
        $this->assertSame(DeliveryOutcome::DELIVERED, $this->service($store, $sender)->run($id)->kind);
        $this->assertSame(2, $store->rows[$id]['attempts'], 'the crashed attempt still consumed its slot');
        $this->assertSame((string) ($this->now - 120), $sender->calls[0]['headers']['X-Wellms-Timestamp'], 'timestamp stays the FIRST claim time');
    }

    public function testAMisconfiguredModuleSendsNothingAndRecordsWhy(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $sender = new ScriptedSender([new SendResult(200)]);

        foreach ([new WebhookSettings('https://luminate.example/hooks', null), new WebhookSettings('http://luminate.example/hooks', self::SECRET), new WebhookSettings(null, self::SECRET)] as $bad) {
            $out = $this->service($store, $sender, $bad)->run($id);
            $this->assertSame(DeliveryOutcome::RETRY, $out->kind);
            $this->now = $store->rows[$id]['next'];
        }

        $this->assertCount(0, $sender->calls, 'never sent unsigned, over http, or to nowhere');
        $this->assertStringContainsString('refused', (string) $store->rows[$id]['last_error']);
        $this->assertSame('pending', $store->rows[$id]['status'], 'still deliverable once the config is fixed');
    }

    public function testTheStoredErrorNeverContainsTheBodyOrTheSecret(): void
    {
        $store = new MemoryStore();
        $id = $this->recorded($store);
        $this->service($store, new ScriptedSender([new SendResult(null, 'cURL error 28: timed out')]))->run($id);

        $err = (string) $store->rows[$id]['last_error'];
        $this->assertStringNotContainsString(self::SECRET, $err);
        $this->assertStringNotContainsString('attempt_id', $err);
    }

    public function testPruneRemovesOldDeliveredAndDeadRowsButNeverPendingOnes(): void
    {
        $store = new MemoryStore();
        $svc = $this->service($store, new ScriptedSender([new SendResult(200)]));
        $old = $this->recorded($store);
        $svc->run($old);                                        // delivered "now"
        $pending = $this->recorded($store);                     // never attempted
        $dead = $this->recorded($store);
        $store->markDead($dead, 500, 'x', $this->now);

        $this->assertSame(0, $store->prune($this->now - 1, $this->now - 1), 'nothing is old enough yet');
        $this->assertSame(2, $store->prune($this->now + 1, $this->now + 1));
        $this->assertArrayHasKey($pending, $store->rows, 'an undelivered event is never pruned, however old');
        $this->assertArrayNotHasKey($old, $store->rows);
        $this->assertArrayNotHasKey($dead, $store->rows);
    }
}
