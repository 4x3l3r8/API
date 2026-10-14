<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks\Console;

use Illuminate\Console\Command;
use Luminate\Tenancy\Webhooks\DeliveryStore;

/**
 * Retention for the outbox of the current tenant: delivered rows are kept `retention_days` (default 14, comfortably longer
 * than any retry window), dead-lettered rows `dead_retention_days` (default 90) so an operator can still see what failed.
 */
final class PruneWebhooksCommand extends Command
{
    protected $signature = 'luminate:webhooks:prune';
    protected $description = 'Delete old delivered / dead-lettered webhook deliveries for the current tenant.';

    public function handle(DeliveryStore $store): int
    {
        $cfg = (array) config('luminate-tenancy.webhooks');
        $now = time();
        $deleted = $store->prune(
            $now - max(1, (int) ($cfg['retention_days'] ?? 14)) * 86400,
            $now - max(1, (int) ($cfg['dead_retention_days'] ?? 90)) * 86400
        );
        $this->line(json_encode(['event' => 'webhooks.pruned', 'deleted' => $deleted]));

        return self::SUCCESS;
    }
}
