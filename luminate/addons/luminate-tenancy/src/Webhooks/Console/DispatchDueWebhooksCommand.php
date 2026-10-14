<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks\Console;

use Illuminate\Console\Command;
use Luminate\Tenancy\Webhooks\DeliverWebhook;
use Luminate\Tenancy\Webhooks\DeliveryStore;
use Luminate\Tenancy\Webhooks\WebhookSettings;

/**
 * The self-healing half of delivery. Scheduled every minute for each ACTIVE tenant (a suspended tenant is skipped by the
 * per-tenant scheduler, so nothing is sent for it). Re-dispatches every due pending row: this recovers deliveries whose job
 * was lost, dead-lettered, or whose lease expired because a worker crashed mid-send. Claiming is atomic, so dispatching a
 * row that is already being worked is harmless.
 */
final class DispatchDueWebhooksCommand extends Command
{
    protected $signature = 'luminate:webhooks:dispatch-due {--limit=200}';
    protected $description = 'Re-dispatch due pending webhook deliveries for the current tenant.';

    public function handle(DeliveryStore $store, WebhookSettings $settings): int
    {
        if (!$settings->enabled()) {
            return self::SUCCESS;
        }
        $ids = $store->dueIds(time(), max(1, (int) $this->option('limit')));
        foreach ($ids as $id) {
            DeliverWebhook::dispatch($id);
        }
        $this->line(json_encode(['event' => 'webhooks.dispatched_due', 'count' => count($ids)]));

        return self::SUCCESS;
    }
}
