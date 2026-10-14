<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries only a row id. The outbox row is authoritative: if this job is lost, dead-lettered (e.g. the tenant was
 * suspended) or never runs, the per-tenant sweeper (luminate:webhooks:dispatch-due) re-dispatches it from the row.
 * A failed delivery is NOT a failed job: the outcome is recorded on the row and a delayed job is scheduled for the retry.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryRowId)
    {
    }

    public function handle(DeliveryService $service): void
    {
        $outcome = $service->run($this->deliveryRowId);

        if ($outcome->kind === DeliveryOutcome::RETRY && $outcome->retryIn !== null) {
            // timeliness only: the sweeper is the safety net, and claiming is atomic so a duplicate is a no-op
            self::dispatch($this->deliveryRowId)->delay($outcome->retryIn);
        }
    }
}
