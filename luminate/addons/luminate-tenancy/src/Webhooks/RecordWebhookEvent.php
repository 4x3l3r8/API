<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Luminate\Tenancy\Context\CurrentTenant;

/**
 * Synchronous listener for the Wellms events in contract §F. SYNCHRONOUS on purpose: Wellms' Course* events keep their
 * user/course in private properties of an abstract parent, so a queued listener would receive an uninitialised event.
 * It reduces the event to plain scalars and writes the outbox row; delivery happens later, from the row.
 *
 * It must never break the business operation that fired the event: the insert runs in a savepoint (a failed insert cannot
 * poison the surrounding transaction) and every failure is logged and swallowed. A lost event is covered by the
 * reconciliation pull, which is the contract's system of record.
 */
final class RecordWebhookEvent
{
    public function __construct(
        private readonly WebhookSettings $settings,
        private readonly EventMapper $mapper,
        private readonly DeliveryStore $store,
        private readonly CurrentTenant $tenant,
    ) {
    }

    public function handle(object $event): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        if (($why = $this->settings->unusable()) !== null) {
            Log::critical('webhook: event not recorded, module misconfigured: ' . $why, ['event_class' => $event::class]);

            return;
        }
        $tid = $this->tenant->id();
        if ($tid === null) {
            // an event that cannot be attributed to a tenant must not be sent to anyone
            Log::critical('webhook: event dropped, no active tenant', ['event_class' => $event::class]);

            return;
        }

        try {
            $mapped = $this->mapper->map($event);
            if ($mapped === null) {
                Log::warning('webhook: event not mapped (unknown event or missing ids)', ['event_class' => $event::class, 'tenant' => $tid]);

                return;
            }
            [$name, $data] = $mapped;

            $now = time();
            $deliveryId = (string) Str::uuid();
            $body = json_encode([
                'event' => $name,
                'delivery_id' => $deliveryId,
                'tenant_id' => $tid,
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $id = DB::transaction(fn () => $this->store->insert($deliveryId, $name, $body, $now));
            DeliverWebhook::dispatch($id)->afterCommit();
        } catch (\Throwable $e) {
            Log::error('webhook: could not record event', ['event_class' => $event::class, 'tenant' => $tid, 'error' => $e::class . ': ' . mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
