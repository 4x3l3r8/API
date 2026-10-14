<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

use Closure;

/**
 * One delivery attempt, end to end: claim -> sign -> POST -> record the result. Holds all the retry / dead-letter policy
 * (contract v4 §F) and knows nothing about queues or the framework, so it is unit-tested with an in-memory store and sender.
 *
 * Guarantees:
 *  - at-least-once: the row is the source of truth, not the queue. A lost job, a crashed worker or a suspended tenant just
 *    leaves a due row, and the per-tenant sweeper picks it up again.
 *  - the SAME delivery id, first-send timestamp, raw body and therefore signature are used on every retry;
 *  - an attempt is counted when it is claimed, so a crash mid-send still consumes it.
 */
final class DeliveryService
{
    /** @param Closure():int $clock Unix seconds */
    public function __construct(
        private readonly DeliveryStore $store,
        private readonly Sender $sender,
        private readonly WebhookSettings $settings,
        private readonly Backoff $backoff,
        private readonly Closure $clock,
        private readonly int $leaseSeconds = 120,
    ) {
    }

    public function run(int $id): DeliveryOutcome
    {
        $now = ($this->clock)();
        $delivery = $this->store->claim($id, $this->leaseSeconds, $now);
        if ($delivery === null) {
            return new DeliveryOutcome(DeliveryOutcome::SKIPPED);
        }

        $refusal = $this->settings->unusable();
        $result = $refusal !== null
            ? new SendResult(null, 'sending refused: ' . $refusal)
            : $this->sender->post(
                (string) $this->settings->url,
                $delivery->body,
                $this->headers($delivery),
                $this->settings->timeout,
                $this->settings->connectTimeout,
            );

        $now = ($this->clock)();
        if ($result->ok()) {
            $this->store->markDelivered($delivery->id, (int) $result->status, $now);

            return new DeliveryOutcome(DeliveryOutcome::DELIVERED, null, $result->status);
        }

        $error = $result->error ?? ('HTTP ' . $result->status);
        if ($this->backoff->exhausted($delivery->attempts, $now - $delivery->createdAt)) {
            $this->store->markDead($delivery->id, $result->status, $error, $now);

            return new DeliveryOutcome(DeliveryOutcome::DEAD, null, $result->status);
        }

        $delay = $this->backoff->delayAfter($delivery->attempts);
        $this->store->markFailed($delivery->id, $result->status, $error, $now + $delay, $now);

        return new DeliveryOutcome(DeliveryOutcome::RETRY, $delay, $result->status);
    }

    /** @return array<string,string> */
    private function headers(Delivery $d): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Wellms-Luminate-Webhooks/1',
            'X-Wellms-Event' => $d->event,
            'X-Wellms-Delivery' => $d->deliveryId,
            'X-Wellms-Timestamp' => (string) $d->timestamp,
            'X-Wellms-Signature' => WebhookSigner::signature((string) $this->settings->secret, $d->deliveryId, $d->timestamp, $d->body),
        ];
    }
}
